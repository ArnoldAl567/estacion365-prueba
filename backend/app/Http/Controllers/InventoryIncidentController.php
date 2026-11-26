<?php

namespace App\Http\Controllers;

use App\Services\InventoryLedger;
use App\Services\OperationsAudit;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryIncidentController extends Controller
{
    public function __construct(private InventoryLedger $ledger) {}

    public function index(Request $request): array
    {
        $f = $request->validate(['kind' => 'required|in:merma,desvio', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'state' => 'nullable|in:pending,applied,cancelled', 'item_id' => 'nullable|integer', 'category_id' => 'nullable|integer', 'page' => 'nullable|integer|min:1']);
        abort_if(! empty($f['from']) && ! empty($f['to']) && $f['to'] < $f['from'], 422, 'La fecha final debe ser igual o posterior a la inicial.');
        $q = DB::table('inventory_incidents as e')->join('inventory_items as i', 'i.id', '=', 'e.item_id')->join('inventory_units as u', 'u.id', '=', 'i.unit_id')
            ->join('users as author', 'author.id', '=', 'e.created_by')->leftJoin('users as approver', 'approver.id', '=', 'e.applied_by')->leftJoin('users as canceller', 'canceller.id', '=', 'e.cancelled_by')->where('e.kind', $f['kind']);
        if (! empty($f['from'])) {
            $q->where('e.created_at', '>=', CarbonImmutable::parse($f['from'], 'America/Lima')->startOfDay()->utc());
        }
        if (! empty($f['to'])) {
            $q->where('e.created_at', '<', CarbonImmutable::parse($f['to'], 'America/Lima')->addDay()->startOfDay()->utc());
        }
        foreach (['state' => 'e.state', 'item_id' => 'e.item_id', 'category_id' => 'i.category_id'] as $key => $column) {
            if (! empty($f[$key])) {
                $q->where($column, $f[$key]);
            }
        }
        $summary = (clone $q)->selectRaw("count(*) filter (where e.state='pending')::integer as pending,
            coalesce(-sum(e.cost_cents) filter (where e.state='applied' and e.delta_milli<0),0)::bigint as loss_cents,
            coalesce(sum(e.cost_cents) filter (where e.state='applied' and e.delta_milli>0),0)::bigint as surplus_cents")->first();

        return ['records' => $q->orderByDesc('e.id')->paginate(30, ['e.*', 'i.name', 'u.symbol', 'author.name as author', 'approver.name as approver', 'canceller.name as canceller']),
            'summary' => array_map('intval', (array) $summary),
            'items' => DB::table('inventory_items as i')->join('inventory_units as u', 'u.id', '=', 'i.unit_id')->orderBy('i.name')->get(['i.id', 'i.name', 'i.stock_milli', 'i.stock_value_cents', 'i.category_id', 'u.symbol']),
            'categories' => DB::table('inventory_categories')->orderBy('name')->get()];
    }

    public function store(Request $request): array
    {
        $d = $request->validate(['request_id' => 'required|uuid', 'kind' => 'required|in:merma,desvio', 'item_id' => 'required|integer|exists:inventory_items,id',
            'quantity_milli' => 'required_if:kind,merma|nullable|integer|between:1,1000000000', 'counted_milli' => 'required_if:kind,desvio|nullable|integer|between:0,1000000000',
            'incoming_cost_cents' => 'nullable|integer|between:0,100000000', 'expected_stock_milli' => 'required|integer|min:0', 'expected_stock_value_cents' => 'required|integer|min:0',
            'reason' => 'required|string|min:3|max:180', 'notes' => 'nullable|string|max:500']);
        abort_if(trim($d['reason']) === '', 422, 'Indica el motivo.');

        return DB::transaction(function () use ($d, $request) {
            $this->ledger->lock();
            if ($old = DB::table('inventory_incidents')->where('request_id', $d['request_id'])->first()) {
                abort_unless($old->created_by === $request->user()->id && $old->item_id === $d['item_id'] && $old->kind === $d['kind'] && $old->reason === trim($d['reason'])
                    && $old->system_milli === $d['expected_stock_milli'] && $old->system_value_cents === $d['expected_stock_value_cents']
                    && ($old->notes ?? '') === ($d['notes'] ?? '') && ($d['kind'] === 'merma' ? $old->delta_milli === -$d['quantity_milli'] : $old->counted_milli === $d['counted_milli'])
                    && ! ($d['kind'] === 'desvio' && $old->system_milli === 0 && $old->delta_milli > 0 && $old->cost_cents !== ($d['incoming_cost_cents'] ?? null)), 409, 'La solicitud cambió. Revisa el registro.');

                return ['id' => $old->id];
            }
            $i = DB::table('inventory_items')->where('id', $d['item_id'])->lockForUpdate()->first();
            abort_unless($i && $i->stock_milli === $d['expected_stock_milli'] && $i->stock_value_cents === $d['expected_stock_value_cents'], 409, 'El stock cambió. Cierra el formulario y vuelve a abrirlo con los datos actuales.');
            $delta = $d['kind'] === 'merma' ? -$d['quantity_milli'] : $d['counted_milli'] - $i->stock_milli;
            abort_if($delta === 0, 422, 'El conteo coincide con el stock; no hay desvío.');
            abort_if($i->stock_milli + $delta < 0, 422, 'La pérdida supera el stock disponible.');
            $cost = $delta < 0 ? -($delta === -$i->stock_milli ? $i->stock_value_cents : (int) round($i->stock_value_cents * (-$delta) / $i->stock_milli))
                : ($i->stock_milli > 0 ? (int) round($i->stock_value_cents * $delta / $i->stock_milli) : ($d['incoming_cost_cents'] ?? null));
            abort_if($cost === null, 422, 'Indica el valor total del sobrante porque el insumo no tiene existencias para calcular su costo.');
            $id = DB::table('inventory_incidents')->insertGetId(['request_id' => $d['request_id'], 'kind' => $d['kind'], 'item_id' => $i->id, 'system_milli' => $i->stock_milli, 'system_value_cents' => $i->stock_value_cents,
                'counted_milli' => $d['kind'] === 'desvio' ? $d['counted_milli'] : null, 'delta_milli' => $delta, 'cost_cents' => $cost,
                'snapshot_movement_id' => DB::table('inventory_movements')->where('item_id', $i->id)->max('id'), 'reason' => trim($d['reason']), 'notes' => $d['notes'] ?? null, 'state' => 'pending', 'created_by' => $request->user()->id, 'created_at' => now()]);
            OperationsAudit::record($request->user()->id, 'inventario.incidencia.registrada', 'incidencia', $id);

            return ['id' => $id];
        });
    }

    public function apply(Request $request, int $id): array
    {
        abort_unless($request->user()->role === 'admin', 403, 'Solo un administrador puede aplicar ajustes.');

        return DB::transaction(function () use ($request, $id) {
            $this->ledger->lock();
            $e = DB::table('inventory_incidents')->where('id', $id)->lockForUpdate()->first();
            abort_unless($e, 404);
            abort_unless($e->state === 'pending', 409, 'El registro ya fue aplicado o anulado.');
            $i = DB::table('inventory_items')->where('id', $e->item_id)->lockForUpdate()->first();
            abort_unless($i->stock_milli === $e->system_milli && $i->stock_value_cents === $e->system_value_cents
                && DB::table('inventory_movements')->where('item_id', $i->id)->max('id') === $e->snapshot_movement_id, 409, 'Hubo movimientos después del conteo. Anula el pendiente y registra un nuevo conteo o pérdida.');
            $this->ledger->itemMovement($i->id, $e->delta_milli, $e->delta_milli > 0 ? $e->cost_cents : null, ['user_id' => $request->user()->id, 'type' => $e->kind === 'merma' ? 'merma' : 'ajuste_conteo', 'reason' => $e->reason]);
            $movement = DB::table('inventory_movements')->where('item_id', $i->id)->max('id');
            DB::table('inventory_incidents')->where('id', $id)->update(['state' => 'applied', 'applied_by' => $request->user()->id, 'applied_at' => now(), 'movement_id' => $movement]);
            OperationsAudit::record($request->user()->id, 'inventario.incidencia.aplicada', 'incidencia', $id);

            return ['applied' => true];
        });
    }

    public function cancel(Request $request, int $id): array
    {
        $d = $request->validate(['reason' => 'required|string|min:3|max:180']);
        abort_if(trim($d['reason']) === '', 422, 'Indica el motivo.');

        return DB::transaction(function () use ($request, $id, $d) {
            $this->ledger->lock();
            $e = DB::table('inventory_incidents')->where('id', $id)->lockForUpdate()->first();
            abort_unless($e, 404);
            abort_if($e->state === 'cancelled', 409, 'El registro ya fue anulado.');
            abort_unless($request->user()->role === 'admin' || ($e->state === 'pending' && $e->created_by === $request->user()->id), 403);
            $reverse = null;
            if ($e->state === 'applied') {
                abort_unless(DB::table('inventory_movements')->where('item_id', $e->item_id)->max('id') === $e->movement_id, 409, 'El insumo tiene movimientos posteriores. No se puede revertir automáticamente.');
                $i = DB::table('inventory_items')->where('id', $e->item_id)->lockForUpdate()->first();
                abort_if($i->stock_milli - $e->delta_milli < 0 || $i->stock_value_cents - $e->cost_cents < 0, 422, 'No hay stock o valor suficiente para revertir.');
                DB::table('inventory_items')->where('id', $i->id)->update(['stock_milli' => $i->stock_milli - $e->delta_milli, 'stock_value_cents' => $i->stock_value_cents - $e->cost_cents]);
                $reverse = DB::table('inventory_movements')->insertGetId(['item_id' => $i->id, 'user_id' => $request->user()->id, 'type' => $e->kind === 'merma' ? 'reversa_merma' : 'reversa_conteo', 'quantity_milli' => -$e->delta_milli, 'cost_cents' => -$e->cost_cents, 'reason' => trim($d['reason']), 'created_at' => now()]);
            }
            DB::table('inventory_incidents')->where('id',$id)->update(['state' => 'cancelled', 'cancelled_by' => $request->user()->id, 'cancelled_at' => now(), 'cancellation_reason' => trim($d['reason']), 'reversal_id' => $reverse]);
            OperationsAudit::record($request->user()->id,'inventario.incidencia.anulada','incidencia',$id);

            return ['cancelled' => true];
        });
    }
}
