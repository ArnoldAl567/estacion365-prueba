<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OperationsAudit;
use App\Services\SalonLayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashController extends Controller
{
    public function show(): array
    {
        $shift = $this->openShift();
        $orders = Order::where('cash_eligible', true)->where('status', '!=', 'cancelled')
            ->where(fn ($query) => $query->whereIn('status', ['pending', 'preparing', 'ready'])
                ->orWhere(fn ($query) => $query->where('delivery_method', 'table')->whereNull('table_closed_at'))
                ->orWhere(fn ($query) => $query->where('status', 'delivered')
                    ->whereNotExists(fn ($payments) => $payments->selectRaw('1')->from('order_payments')->whereColumn('order_id', 'orders.id'))))
            ->latest('id')->simplePaginate(35, ['orders.*'], 'ordersPage');
        $paid = DB::table('order_payments')->whereIn('order_id', $orders->getCollection()->modelKeys())->get()->keyBy('order_id');

        return [
            'templates' => DB::table('cash_shift_templates')->orderBy('id')->get(),
            'shift' => $shift ? [...(array) $shift, ...$this->totals($shift)] : null,
            'orders' => $orders->getCollection()->map(fn ($o) => [...$o->staffDetails(), 'paymentRecord' => $paid[$o->id] ?? null]),
            'ordersPage' => $orders->currentPage(), 'hasMoreOrders' => $orders->hasMorePages(),
            'methods' => DB::table('payment_methods')->where('active', true)->orderBy('name')->get(),
            'history' => DB::table('cash_shifts')->whereNotNull('closed_at')->latest('id')->limit(20)->get(),
        ];
    }

    public function open(Request $request): array
    {
        $data = $request->validate(['name' => 'required_without:template_id|nullable|string|max:80',
            'template_id' => 'nullable|integer|exists:cash_shift_templates,id', 'opening_cents' => 'required|integer|between:0,100000000']);
        return DB::transaction(function () use ($data, $request) {
            $this->lock();
            abort_if($this->openShift(), 409, 'Ya hay un turno abierto.');
            if (!empty($data['template_id'])) {
                $template = DB::table('cash_shift_templates')->where('id', $data['template_id'])->sharedLock()->first();
                abort_unless($template?->active, 422, 'Selecciona un turno configurado y activo.');
                $data['name'] = $template->name;
            }
            $id = DB::table('cash_shifts')->insertGetId([...$data, 'opened_by' => $request->user()->id, 'opened_at' => now()]);
            OperationsAudit::record($request->user()->id, 'caja.apertura', 'turno', $id, ['importe' => $data['opening_cents']]);
            return ['id' => $id];
        });
    }

    public function payment(Request $request, Order $order): array
    {
        $data = $request->validate(['method' => 'required|alpha_dash:ascii|max:32', 'expected_total_cents' => 'required|integer|min:0']);
        return DB::transaction(function () use ($data, $order, $request) {
            $this->lock();
            $current = Order::lockForUpdate()->findOrFail($order->id);
            $existing = DB::table('order_payments')->where('order_id', $order->id)->first();
            if ($existing) {
                abort_unless($existing->method === $data['method'] && (int) $existing->amount_cents === (int) $data['expected_total_cents'], 409, 'El pedido ya fue cobrado.');
                return ['id' => $existing->id];
            }
            $shift = $this->requireShift();
            abort_unless($current->cash_eligible, 422, 'Este pedido histórico es anterior al módulo de Caja.');
            abort_if($current->status === 'cancelled', 422, 'El pedido fue cancelado.');
            abort_unless((int) $current->total_cents === (int) $data['expected_total_cents'], 409, 'El importe cambió. Actualiza la vista.');
            $method = DB::table('payment_methods')->where('code', $data['method'])->where('active', true)->sharedLock()->first();
            abort_unless($method, 422, 'El medio de pago ya no está disponible.');
            $id = DB::table('order_payments')->insertGetId([
                'order_id' => $current->id, 'shift_id' => $shift->id, 'user_id' => $request->user()->id,
                'method' => $method->code, 'method_name' => $method->name, 'cash' => $method->cash,
                'amount_cents' => $current->total_cents, 'paid_at' => now(),
            ]);
            OperationsAudit::record($request->user()->id, 'caja.cobro', 'pedido', $current->id, ['importe' => $current->total_cents, 'medio' => $method->name]);
            return ['id' => $id];
        });
    }

    public function saveTemplate(Request $request): array
    {
        $request->merge(['name'=>is_string($request->input('name'))?trim($request->input('name')):$request->input('name')]);
        $d=$request->validate(['id'=>'nullable|integer|exists:cash_shift_templates,id','name'=>'required|string|max:80','active'=>'required|boolean']);
        return DB::transaction(function()use($d,$request){
            $this->lock(); $id=$d['id']??null;
            if($id) abort_unless(DB::table('cash_shift_templates')->where('id',$id)->lockForUpdate()->first(),404,'El turno configurado ya no existe.');
            $q=DB::table('cash_shift_templates')->whereRaw('lower(trim(name))=lower(?)',[$d['name']]);if($id)$q->where('id','!=',$id);
            abort_if($q->exists(),422,'Ya existe un turno con ese nombre.');
            if($id)DB::table('cash_shift_templates')->where('id',$id)->update(['name'=>$d['name'],'active'=>$d['active']]);
            else $id=DB::table('cash_shift_templates')->insertGetId(['name'=>$d['name'],'active'=>$d['active']]);
            OperationsAudit::record($request->user()->id,'caja.turno.configurado','turno_configurado',$id,['nombre'=>$d['name'],'activo'=>$d['active']]);
            return ['id'=>$id];
        });
    }

    public function deleteTemplate(Request $request,int $id): array
    {
        return DB::transaction(function()use($request,$id){
            $this->lock();$row=DB::table('cash_shift_templates')->where('id',$id)->lockForUpdate()->first();abort_unless($row,404);
            abort_if(DB::table('cash_shifts')->where('template_id',$id)->exists(),422,'Este turno ya tiene aperturas registradas. Puedes desactivarlo para conservar el historial.');
            DB::table('cash_shift_templates')->where('id',$id)->delete();
            OperationsAudit::record($request->user()->id,'caja.turno.eliminado','turno_configurado',$id,['nombre'=>$row->name]);
            return ['deleted'=>true];
        });
    }

    public function close(Request $request): array
    {
        $data = $request->validate(['shift_id' => 'required|integer|min:1', 'counted_cents' => 'required|integer|between:0,100000000']);
        return DB::transaction(function () use ($data, $request) {
            $this->lock();
            $shift = $this->requireShift();
            abort_unless($shift->id === (int) $data['shift_id'], 409, 'El turno ya cambió.');
            $totals = $this->totals($shift);
            DB::table('cash_shifts')->where('id', $shift->id)->update([
                'closed_by' => $request->user()->id, 'closed_at' => now(),
                'counted_cents' => $data['counted_cents'], 'expected_cents' => $totals['expected_cents'],
            ]);
            OperationsAudit::record($request->user()->id, 'caja.cierre', 'turno', $shift->id, [
                'esperado' => $totals['expected_cents'], 'contado' => $data['counted_cents'],
            ]);
            return ['difference_cents' => $data['counted_cents'] - $totals['expected_cents']];
        });
    }

    public function closeTable(Request $request, Order $order, SalonLayout $layout): array
    {
        return DB::transaction(function () use ($request, $order, $layout) {
            DB::table('salon_layout')->where('id', 1)->lockForUpdate()->first();
            $anchor = Order::findOrFail($order->id);
            abort_unless($anchor->delivery_method === 'table' && ! $anchor->table_closed_at, 409, 'La mesa ya está cerrada o el pedido cambió de modalidad.');
            $key = $layout->tableKey($anchor->table_label ?? '');
            $orders = Order::where('delivery_method', 'table')->where('status', '!=', 'cancelled')->whereNull('table_closed_at')
                ->orderBy('id')->lockForUpdate()->get()
                ->filter(fn ($candidate) => $key === '' ? $candidate->id === $anchor->id : $layout->tableKey($candidate->table_label ?? '') === $key);
            abort_if($orders->isEmpty() || $orders->contains(fn ($candidate) => $candidate->status !== 'delivered'), 409, 'Todos los pedidos de la mesa deben estar entregados.');
            $ids = $orders->modelKeys();
            abort_unless(DB::table('order_payments')->whereIn('order_id', $ids)->count() === count($ids), 409, 'Todos los pedidos de la mesa deben estar cobrados.');
            Order::whereIn('id', $ids)->update(['table_closed_at' => now(), 'table_closed_by' => $request->user()->id]);
            OperationsAudit::record($request->user()->id, 'caja.mesa_cerrada', 'pedido', $anchor->id, ['pedidos' => $ids]);
            return ['closed' => count($ids)];
        });
    }

    private function lock(): void
    {
        DB::select('select pg_advisory_xact_lock(3652040)');
    }

    private function openShift(): ?object
    {
        return DB::table('cash_shifts')->whereNull('closed_at')->first();
    }

    private function requireShift(): object
    {
        $shift = $this->openShift();
        abort_unless($shift, 409, 'Abre un turno de caja para continuar.');
        return $shift;
    }

    private function totals(object $shift): array
    {
        $payments = DB::table('order_payments')->where('shift_id', $shift->id);
        $income = (int) (clone $payments)->sum('amount_cents');
        $cash = (int) $payments->where('cash', true)->sum('amount_cents');
        $expenses = (int) DB::table('cash_expenses')->where('shift_id', $shift->id)->sum('amount_cents');
        return ['income_cents' => $income, 'cash_cents' => $cash, 'expenses_cents' => $expenses,
            'expected_cents' => $shift->opening_cents + $cash - $expenses];
    }
}
