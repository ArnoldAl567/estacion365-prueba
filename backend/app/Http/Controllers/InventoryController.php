<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\InventoryLedger;
use App\Services\DailyProduction;
use App\Services\OperationsAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function __construct(private InventoryLedger $ledger) {}

    public function index(Request $request): array
    {
        $day = $request->validate(['day' => 'nullable|date_format:Y-m-d'])['day'] ?? now('America/Lima')->toDateString();
        return [
            'categories' => DB::table('inventory_categories as c')->leftJoin('inventory_items as i', 'i.category_id', '=', 'c.id')
                ->groupBy('c.id', 'c.name')->orderBy('c.name')->selectRaw('c.id, c.name, count(i.id)::integer as item_count')->get(),
            'productionDay' => $day,
            'dailyPlans' => config('restaurant.daily_availability') ? app(DailyProduction::class)->overview($day) : [],
            'units' => DB::table('inventory_units')->orderBy('name')->get(),
            'items' => DB::table('inventory_items')->join('inventory_units', 'inventory_units.id', '=', 'inventory_items.unit_id')
                ->orderBy('inventory_items.name')->get(['inventory_items.*', 'inventory_units.symbol']),
            'suppliers' => DB::table('inventory_suppliers')->orderBy('name')->get(),
            'purchases' => DB::table('inventory_purchases')->join('inventory_suppliers', 'inventory_suppliers.id', '=', 'inventory_purchases.supplier_id')
                ->latest('inventory_purchases.id')->limit(30)->get(['inventory_purchases.*', 'inventory_suppliers.name as supplier']),
            'movements' => DB::table('inventory_movements')->leftJoin('inventory_items', 'inventory_items.id', '=', 'inventory_movements.item_id')
                ->latest('inventory_movements.id')->paginate(40, ['inventory_movements.*', 'inventory_items.name']),
            'products' => DB::table('menu_products')->orderBy('name')->get(['item_key', 'name', 'kind']),
            'recipes' => DB::table('inventory_recipes')->join('menu_products', 'menu_products.item_key', '=', 'inventory_recipes.product_key')
                ->orderBy('menu_products.name')->get(['inventory_recipes.*', 'menu_products.name'])
                ->map(fn ($r) => [...(array) $r, 'components' => json_decode($r->components, true, flags: JSON_THROW_ON_ERROR)]),
            'batches' => DB::table('inventory_batches')->join('inventory_recipes', 'inventory_recipes.id', '=', 'inventory_batches.recipe_id')
                ->join('menu_products', 'menu_products.item_key', '=', 'inventory_recipes.product_key')
                ->latest('inventory_batches.id')->paginate(35, ['inventory_batches.*', 'menu_products.name'], 'batchPage'),
            'orders' => Order::where('cash_eligible', true)->whereIn('status', ['ready', 'delivered'])
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('inventory_consumptions')->whereColumn('order_id', 'orders.id'))
                ->latest('id')->simplePaginate(30, ['id', 'status', 'table_label', 'created_at'], 'orderPage'),
        ];
    }

    public function purchaseDetail(int $id): array
    {
        $purchase = DB::table('inventory_purchases')
            ->join('inventory_suppliers', 'inventory_suppliers.id', '=', 'inventory_purchases.supplier_id')
            ->where('inventory_purchases.id', $id)
            ->first(['inventory_purchases.id', 'inventory_purchases.reference', 'inventory_purchases.created_at',
                'inventory_purchases.total_cents', 'inventory_purchases.cancelled_at', 'inventory_purchases.cancellation_reason',
                'inventory_purchases.cancelled_by', 'inventory_suppliers.name as supplier']);
        abort_unless($purchase, 404, 'La compra no existe.');
        $lines = DB::table('inventory_movements')
            ->join('inventory_items', 'inventory_items.id', '=', 'inventory_movements.item_id')
            ->join('inventory_units', 'inventory_units.id', '=', 'inventory_items.unit_id')
            ->where('inventory_movements.purchase_id', $id)->where('inventory_movements.type', 'compra')->orderBy('inventory_movements.id')
            ->get(['inventory_movements.id', 'inventory_items.name', 'inventory_units.symbol',
                'inventory_movements.quantity_milli', 'inventory_movements.cost_cents']);
        return [...(array) $purchase, 'cancelled_by_name' => $purchase->cancelled_by ? DB::table('users')->where('id', $purchase->cancelled_by)->value('name') : null, 'lines' => $lines];
    }

    public function cancelPurchase(Request $request, int $id): array
    {
        $request->merge(['reason' => is_string($request->input('reason')) ? trim($request->input('reason')) : $request->input('reason')]);
        $data = $request->validate(['reason' => 'required|string|min:3|max:180']);
        return DB::transaction(function () use ($request, $id, $data) {
            $this->ledger->lock();
            $purchase = DB::table('inventory_purchases')->where('id', $id)->lockForUpdate()->first();
            abort_unless($purchase, 404, 'La compra no existe.');
            abort_if($purchase->cancelled_at, 409, 'Esta compra ya fue anulada.');
            $lines = DB::table('inventory_movements')->where('purchase_id', $id)->where('type', 'compra')->orderBy('id')->get();
            abort_if($lines->isEmpty() || (int) $lines->sum('cost_cents') !== (int) $purchase->total_cents,
                422, 'La compra no tiene un detalle de movimientos completo para revertirla.');
            foreach ($lines as $line) $this->ledger->reversePurchaseLine($line, $request->user()->id, $data['reason']);
            DB::table('inventory_purchases')->where('id', $id)->update(['cancelled_at' => now(),
                'cancelled_by' => $request->user()->id, 'cancellation_reason' => $data['reason']]);
            OperationsAudit::record($request->user()->id, 'inventario.compra.anulada', 'compra', $id,
                ['motivo' => $data['reason'], 'importe' => $purchase->total_cents]);
            return ['cancelled' => true];
        });
    }

    public function availability(Request $request): array
    {
        abort_unless(config('restaurant.daily_availability'), 404);
        $day = $request->validate(['day' => 'required|date_format:Y-m-d'])['day'];
        return ['dailyPlans' => app(DailyProduction::class)->overview($day)];
    }

    public function batchDetail(int $id): array
    {
        $batch = DB::table('inventory_batches as b')->join('inventory_recipes as r', 'r.id', '=', 'b.recipe_id')
            ->join('menu_products as p', 'p.item_key', '=', 'r.product_key')->where('b.id', $id)
            ->first(['b.id', 'b.portions', 'b.remaining', 'b.cost_cents', 'b.created_at', 'p.name']);
        abort_unless($batch, 404, 'El lote no existe.');
        // Read the original ledger, not the recipe that may have changed after production.
        $lines = DB::table('inventory_movements as m')->join('inventory_items as i', 'i.id', '=', 'm.item_id')
            ->join('inventory_units as u', 'u.id', '=', 'i.unit_id')->where('m.batch_id', $id)->where('m.type', 'produccion')
            ->groupBy('i.id', 'i.name', 'u.symbol')->orderBy('i.name')
            ->selectRaw('i.id as item_id, i.name, u.symbol, -sum(m.quantity_milli) as quantity_milli, -sum(m.cost_cents) as cost_cents')
            ->get()->map(fn ($line) => [...(array) $line, 'quantity_milli' => (int) $line->quantity_milli, 'cost_cents' => (int) $line->cost_cents]);
        return [...(array) $batch, 'lines' => $lines];
    }

    public function master(Request $request, string $kind): array
    {
        $tables = ['units' => 'inventory_units', 'suppliers' => 'inventory_suppliers', 'items' => 'inventory_items'];
        $table = $tables[$kind];
        $id = $request->validate(['id' => ['nullable', 'integer', Rule::exists($table, 'id')]])['id'] ?? null;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.($kind === 'units' ? 80 : 120), Rule::unique($table)->ignore($id)],
            ...match ($kind) {
                'units' => ['symbol' => ['required', 'string', 'max:16', Rule::unique($table)->ignore($id)]],
                'suppliers' => ['contact' => 'nullable|string|max:120'],
                'items' => ['unit_id' => 'required|integer|exists:inventory_units,id', 'category' => 'nullable|string|max:80',
                    'category_id' => 'nullable|integer|exists:inventory_categories,id',
                    'minimum_milli' => 'required|integer|between:0,1000000000'],
            },
        ]);
        return DB::transaction(function () use ($kind, $table, $id, $data, $request) {
            $this->ledger->lock();
            if ($kind === 'items' && array_key_exists('category_id', $data)) {
                $category = $data['category_id'] ? DB::table('inventory_categories')->where('id', $data['category_id'])->first() : null;
                abort_if($data['category_id'] && !$category, 422, 'La categoría ya no existe. Selecciona otra categoría.');
                $data['category'] = $category?->name;
            }
            if ($id && $kind === 'items') {
                $current = DB::table($table)->where('id', $id)->first();
                abort_if((int) $data['unit_id'] !== $current->unit_id && (
                    DB::table('inventory_movements')->where('item_id', $id)->exists()
                    || DB::table('inventory_incidents')->where('item_id', $id)->exists()
                    || DB::table('inventory_recipes')->whereJsonContains('components', [['item_id' => (int) $id]])->exists()
                ), 422, 'La unidad de un insumo referenciado no puede cambiar. Crea otro insumo para la nueva unidad.');
            }
            if ($id) DB::table($table)->where('id', $id)->update($data);
            else $id = DB::table($table)->insertGetId($data);
            OperationsAudit::record($request->user()->id, 'inventario.'.$kind.'.guardado', $kind, $id);
            return ['id' => $id];
        });
    }

    public function deleteUnit(Request $request, int $id): array
    {
        return DB::transaction(function () use ($request, $id) {
            $this->ledger->lock();
            $unit = DB::table('inventory_units')->where('id', $id)->lockForUpdate()->first();
            abort_unless($unit, 404, 'La unidad ya no existe.');
            abort_if(DB::table('inventory_items')->where('unit_id', $id)->exists(), 422,
                'No puedes eliminar esta unidad porque está asignada a un insumo.');
            DB::table('inventory_units')->where('id', $id)->delete();
            OperationsAudit::record($request->user()->id, 'inventario.unidad.eliminada', 'unidad', $id,
                ['nombre' => $unit->name, 'simbolo' => $unit->symbol]);
            return ['deleted' => true];
        });
    }

    public function saveCategory(Request $request): array
    {
        $request->merge(['name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name')]);
        $data = $request->validate(['id' => 'nullable|integer|exists:inventory_categories,id', 'name' => 'required|string|max:80']);
        return DB::transaction(function () use ($request, $data) {
            $this->ledger->lock();
            $id = $data['id'] ?? null;
            $duplicate = DB::table('inventory_categories')->whereRaw('lower(trim(name)) = lower(?)', [$data['name']]);
            if ($id) $duplicate->where('id', '!=', $id);
            abort_if($duplicate->exists(), 422, 'Ya existe una categoría de insumos con ese nombre.');
            if ($id) {
                DB::table('inventory_categories')->where('id', $id)->update(['name' => $data['name']]);
                DB::table('inventory_items')->where('category_id', $id)->update(['category' => $data['name']]);
            } else $id = DB::table('inventory_categories')->insertGetId(['name' => $data['name']]);
            OperationsAudit::record($request->user()->id, 'inventario.categoria.guardada', 'categoria_insumo', $id, ['nombre' => $data['name']]);
            return ['id' => $id];
        });
    }

    public function deleteCategory(Request $request, int $id): array
    {
        return DB::transaction(function () use ($request, $id) {
            $this->ledger->lock();
            $category = DB::table('inventory_categories')->where('id', $id)->lockForUpdate()->first();
            abort_unless($category, 404, 'La categoría ya no existe.');
            abort_if(DB::table('inventory_items')->where('category_id', $id)->exists(), 422, 'La categoría tiene insumos asignados. Reasígnalos antes de eliminarla.');
            DB::table('inventory_categories')->where('id', $id)->delete();
            OperationsAudit::record($request->user()->id, 'inventario.categoria.eliminada', 'categoria_insumo', $id, ['nombre' => $category->name]);
            return ['deleted' => true];
        });
    }

    public function purchase(Request $request): array
    {
        $data = $request->validate([
            'request_id' => 'required|uuid', 'supplier_id' => 'required|integer|exists:inventory_suppliers,id', 'reference' => 'nullable|string|max:100',
            'lines' => 'required|array|list|max:100', 'lines.*.item_id' => 'required|integer|distinct|exists:inventory_items,id',
            'lines.*.quantity_milli' => 'required|integer|between:1,1000000000', 'lines.*.cost_cents' => 'required|integer|between:1,100000000',
        ]);
        return DB::transaction(function () use ($data, $request) {
            $this->ledger->lock();
            $purchases = DB::table('inventory_purchases');
            if ($existing = $purchases->where('request_id', $data['request_id'])->first()) return ['id' => $existing->id];
            $actor = $request->user()->id;
            $id = DB::table('inventory_purchases')->insertGetId([
                'request_id' => $data['request_id'], 'supplier_id' => $data['supplier_id'], 'reference' => $data['reference'] ?? null,
                'user_id' => $actor, 'total_cents' => array_sum(array_column($data['lines'], 'cost_cents')), 'created_at' => now(),
            ]);
            foreach ($data['lines'] as $line) {
                $this->ledger->itemMovement($line['item_id'], $line['quantity_milli'], $line['cost_cents'], [
                    'user_id' => $actor, 'purchase_id' => $id, 'type' => 'compra',
                ]);
            }
            OperationsAudit::record($actor, 'inventario.compra', 'compra', $id);
            return ['id' => $id];
        });
    }

    public function movement(Request $request): array
    {
        $data = $request->validate([
            'request_id' => 'required|uuid', 'type' => 'required|in:entrada,salida,merma', 'reason' => 'required|string|max:180',
            'item_id' => 'nullable|required_without:batch_id|prohibits:batch_id|integer|exists:inventory_items,id',
            'batch_id' => 'nullable|integer|exists:inventory_batches,id',
            'quantity_milli' => 'required|integer|between:1,1000000000',
            'cost_cents' => 'required_if:type,entrada|nullable|integer|between:0,100000000',
        ]);
        abort_if($data['type'] === 'merma' && empty($data['batch_id']), 422, 'Registra las pérdidas de insumos desde Desvíos y mermas para su revisión y aprobación.');
        return DB::transaction(function () use ($data, $request) {
            $this->ledger->lock();
            if ($old = DB::table('inventory_movements')->where('request_id', $data['request_id'])->first()) return ['id' => $old->id];
            $context = ['user_id' => $request->user()->id, 'type' => $data['type'], 'reason' => $data['reason'], 'request_id' => $data['request_id']];
            if (! empty($data['batch_id'])) {
                abort_unless($data['type'] === 'merma' && $data['quantity_milli'] % 1000 === 0, 422, 'Una merma de lote se registra en porciones enteras.');
                $batch = DB::table('inventory_batches')->where('id', $data['batch_id'])->first();
                $this->ledger->useBatch($batch, intdiv($data['quantity_milli'], 1000), $context);
            } else {
                $quantity = $data['type'] === 'entrada' ? $data['quantity_milli'] : -$data['quantity_milli'];
                $this->ledger->itemMovement($data['item_id'], $quantity, $data['cost_cents'] ?? null, $context);
            }
            $id = DB::table('inventory_movements')->where('request_id', $data['request_id'])->value('id');
            OperationsAudit::record($request->user()->id, 'inventario.'.$data['type'], 'movimiento', $id);
            return ['id' => $id];
        });
    }

    public function recipe(Request $request): array
    {
        $data = $request->validate([
            'product_key' => 'required|string|exists:menu_products,item_key', 'mode' => 'required|in:direct,batch',
            'batch_yield' => 'sometimes|integer|between:1,10000',
            'components' => 'required|array|list|max:60', 'components.*' => 'required|array:item_id,quantity_milli',
            'components.*.item_id' => 'required|integer|distinct|exists:inventory_items,id',
            'components.*.quantity_milli' => 'required|integer|between:1,100000000',
        ]);
        return DB::transaction(function () use ($data, $request) {
            $this->ledger->lock();
            $recipe = DB::table('inventory_recipes')->where('product_key', $data['product_key'])->first();
            $data['batch_yield'] ??= $recipe->batch_yield ?? 1;
            abort_if($recipe && $recipe->mode !== $data['mode'] &&
                DB::table('inventory_batches')->where('recipe_id', $recipe->id)->where('remaining', '>', 0)->exists(),
                422, 'Usa o registra la merma de las porciones existentes antes de cambiar el tipo de receta.');
            $components = collect($data['components'])->map(fn ($line) => [
                'item_id' => (int) $line['item_id'], 'quantity_milli' => (int) $line['quantity_milli'],
            ])->all();
            DB::table('inventory_recipes')->upsert([
                [...$data, 'components' => json_encode($components, JSON_THROW_ON_ERROR)],
            ], ['product_key'], ['mode', 'components', 'batch_yield']);
            OperationsAudit::record($request->user()->id, 'inventario.receta', 'producto', $data['product_key']);
            return ['product_key' => $data['product_key']];
        });
    }

    public function batch(Request $request): array
    {
        $data = $request->validate(['request_id' => 'required|uuid', 'recipe_id' => 'required|integer|exists:inventory_recipes,id',
            'batch_count' => 'nullable|integer|between:1,10000', 'portions' => 'required|integer|between:1,10000']);
        return DB::transaction(function () use ($data, $request) {
            $this->ledger->lock();
            if ($old = DB::table('inventory_batches')->where('request_id', $data['request_id'])->first()) return ['id' => $old->id];
            $recipe = DB::table('inventory_recipes')->where('id', $data['recipe_id'])->first();
            abort_unless($recipe, 422, 'Selecciona una receta.');
            abort_if(isset($data['batch_count']) && $data['portions'] !== $data['batch_count'] * $recipe->batch_yield,
                409, 'El rendimiento del lote cambió. Revisa las porciones antes de registrar la producción.');
            unset($data['batch_count']);
            $actor = $request->user()->id;
            if (config('restaurant.daily_availability')) DB::table('inventory_daily_plans')->insertOrIgnore(['recipe_id' => $recipe->id,
                'day' => now('America/Lima')->toDateString(), 'direct_portions' => 0, 'batch_count' => 0,
                'batch_yield' => $recipe->batch_yield, 'revision' => 0, 'user_id' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            $id = DB::table('inventory_batches')->insertGetId([
                ...$data, 'user_id' => $actor, 'remaining' => $data['portions'], 'cost_cents' => 0,
                'remaining_cost_cents' => 0, 'created_at' => now(),
            ]);
            $cost = $this->ledger->useComponents($recipe, $data['portions'], ['user_id' => $actor, 'batch_id' => $id, 'type' => 'produccion']);
            DB::table('inventory_batches')->where('id', $id)->update(['cost_cents' => $cost, 'remaining_cost_cents' => $cost]);
            OperationsAudit::record($actor, 'inventario.lote', 'lote', $id, ['costo' => $cost]);
            return ['id' => $id];
        });
    }

    public function consume(Request $request, Order $order): array
    {
        return ['consumption' => $this->ledger->consumeOrder($order, $request->user()->id)];
    }

    public function plan(Request $request): array
    {
        abort_unless(config('restaurant.daily_availability'), 404);
        $data = $request->validate(['recipe_id' => 'required|integer|exists:inventory_recipes,id',
            'day' => 'required|date_format:Y-m-d|after_or_equal:'.now('America/Lima')->toDateString(),
            'direct_portions' => 'required|integer|between:0,10000', 'batch_count' => 'required|integer|between:0,10000',
            'expected_revision' => 'nullable|integer|min:0']);
        return DB::transaction(function () use ($data, $request) {
            $this->ledger->lock();
            $recipe = DB::table('inventory_recipes')->where('id', $data['recipe_id'])->first();
            $query = DB::table('inventory_daily_plans')->where('recipe_id', $recipe->id)->where('day', $data['day']);
            $old = $query->first();
            abort_if($old && (!isset($data['expected_revision']) || $data['expected_revision'] !== $old->revision), 409,
                'Otra persona modificó el plan. Cierra el formulario y revisa la versión actual.');
            $used = $old ? DB::table('inventory_portion_reservations')->where('plan_id', $old->id)
                ->whereNull('batch_id')->where('state', '!=', 'released')->sum('portions') : 0;
            abort_if($data['direct_portions'] < $used, 422, 'No puedes reducir el plan por debajo de las porciones ya comprometidas.');
            $values = ['direct_portions' => $data['direct_portions'], 'batch_count' => $data['batch_count'],
                'batch_yield' => $recipe->batch_yield, 'user_id' => $request->user()->id, 'updated_at' => now()];
            if ($old) { $query->update([...$values, 'revision' => $old->revision + 1]); $id = $old->id; }
            else $id = DB::table('inventory_daily_plans')->insertGetId([...$values, 'recipe_id' => $recipe->id,
                'day' => $data['day'], 'revision' => 0, 'created_at' => now()]);
            OperationsAudit::record($request->user()->id, 'inventario.plan', 'plan', $id, $values);
            return ['id' => $id];
        });
    }
}
