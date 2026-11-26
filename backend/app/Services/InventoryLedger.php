<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class InventoryLedger
{
    public function lock(): void
    {
        DB::select('select pg_advisory_xact_lock(3652041)');
    }

    public function itemMovement(int $id, int $quantity, ?int $incomingCost, array $context): int
    {
        $item = DB::table('inventory_items')->where('id', $id)->lockForUpdate()->first();
        abort_unless($item, 422, 'El insumo no existe.');
        abort_if($item->stock_milli + $quantity < 0, 422, 'Stock insuficiente de '.$item->name.'.');
        $cost = $quantity > 0
            ? $incomingCost
            : -$this->portionCost($item->stock_value_cents, $item->stock_milli, -$quantity);
        DB::table('inventory_items')->where('id', $id)->update([
            'stock_milli' => $item->stock_milli + $quantity, 'stock_value_cents' => $item->stock_value_cents + $cost,
        ]);
        $this->record([...$context, 'item_id' => $id, 'quantity_milli' => $quantity, 'cost_cents' => $cost]);
        return abs($cost);
    }

    public function useComponents(object $recipe, int $portions, array $context): int
    {
        $cost = 0;
        foreach (json_decode($recipe->components, true, flags: JSON_THROW_ON_ERROR) as $line) {
            $cost += $this->itemMovement($line['item_id'], -$line['quantity_milli'] * $portions, null, $context);
        }
        return $cost;
    }

    public function reversePurchaseLine(object $line, int $actor, string $reason): void
    {
        $item = DB::table('inventory_items')->where('id', $line->item_id)->lockForUpdate()->first();
        abort_unless($item, 422, 'El insumo de la compra no existe.');
        abort_if(DB::table('inventory_movements')->where('item_id', $line->item_id)->where('id', '>', $line->id)
            ->where('quantity_milli', '<', 0)->exists(), 422,
            'No se puede anular: '.$item->name.' tiene salidas posteriores a esta compra. Registra una corrección con su motivo.');
        abort_if($item->stock_milli < $line->quantity_milli || $item->stock_value_cents < $line->cost_cents,
            422, 'El stock o valor disponible de '.$item->name.' no permite revertir la compra.');
        $quantity = $item->stock_milli - $line->quantity_milli;
        $value = $item->stock_value_cents - $line->cost_cents;
        abort_if($quantity === 0 && $value !== 0, 422, 'La anulación dejaría un valor sin existencias. Revisa los movimientos del insumo.');
        // Reverse the original purchase cost, not today's weighted average.
        DB::table('inventory_items')->where('id', $item->id)->update(['stock_milli' => $quantity, 'stock_value_cents' => $value]);
        $this->record(['item_id' => $item->id, 'purchase_id' => $line->purchase_id, 'user_id' => $actor,
            'type' => 'anulacion_compra', 'quantity_milli' => -$line->quantity_milli,
            'cost_cents' => -$line->cost_cents, 'reason' => $reason]);
    }

    public function useBatch(object $batch, int $portions, array $context): int
    {
        $reserved = DB::table('inventory_portion_reservations')->where('batch_id', $batch->id)->where('state', 'reserved');
        if (isset($context['order_id'])) $reserved->where('order_id', '!=', $context['order_id']);
        abort_if(config('restaurant.daily_availability') && $batch->remaining - $reserved->sum('portions') < $portions, 422, 'El lote tiene porciones reservadas para otros pedidos.');
        abort_if($batch->remaining < $portions, 422, 'El lote no tiene suficientes porciones.');
        $cost = $this->portionCost($batch->remaining_cost_cents, $batch->remaining, $portions);
        DB::table('inventory_batches')->where('id', $batch->id)->update([
            'remaining' => $batch->remaining - $portions, 'remaining_cost_cents' => $batch->remaining_cost_cents - $cost,
        ]);
        $this->record([...$context, 'batch_id' => $batch->id, 'quantity_milli' => -$portions * 1000, 'cost_cents' => -$cost]);
        return $cost;
    }

    public function consumeOrder(Order $order, int $actor): object
    {
        return DB::transaction(function () use ($order, $actor) {
            $this->lock();
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $query = DB::table('inventory_consumptions')->where('order_id', $order->id);
            if ($existing = $query->first()) {
                return $existing;
            }
            abort_unless(in_array($order->status, ['ready', 'delivered'], true), 422, 'El pedido debe estar listo o entregado para registrar su consumo.');
            $required = $this->recipeQuantities($order);
            $recipes = DB::table('inventory_recipes')->whereIn('product_key', array_keys($required))->get()->keyBy('product_key');
            abort_if($recipes->isEmpty(), 422, 'Ningún plato de este pedido tiene una receta configurada.');
            $cost = 0;
            $context = ['order_id' => $order->id, 'user_id' => $actor, 'type' => 'consumo'];
            app(DailyProduction::class)->start($order, $actor);
            $controlled = DB::table('inventory_portion_reservations as r')->join('inventory_daily_plans as p', 'p.id', '=', 'r.plan_id')
                ->join('inventory_recipes as recipe', 'recipe.id', '=', 'p.recipe_id')
                ->where('r.order_id', $order->id)->where('r.state', 'consumed')
                ->get(['recipe.product_key', 'r.portions', 'r.cost_cents']);
            $cost = (int) $controlled->sum('cost_cents');
            foreach ($required as $product => $portions) {
                $portions -= $controlled->where('product_key', $product)->sum('portions');
                if ($portions <= 0) continue;
                $recipe = $recipes->get($product);
                if (! $recipe) {
                    continue;
                }
                $batches = DB::table('inventory_batches')->where('recipe_id', $recipe->id)->where('remaining', '>', 0)->orderBy('id')->get();
                $batches->each(function ($batch) use ($order) {
                    $held = config('restaurant.daily_availability') ? DB::table('inventory_portion_reservations')->where('batch_id', $batch->id)->where('state', 'reserved')->where('order_id', '!=', $order->id)->sum('portions') : 0;
                    $batch->available = max(0, $batch->remaining - $held);
                });
                abort_if($recipe->mode === 'batch' && $batches->sum('available') < $portions, 422, 'Faltan porciones producidas para '.$product.'.');
                foreach ($batches as $batch) {
                    $take = min($portions, $batch->available);
                    if (!$take) continue;
                    $cost += $this->useBatch($batch, $take, $context);
                    $portions -= $take;
                    if (! $portions) break;
                }
                // Already-produced portions consume only their batch balance. Prepare the rest on demand.
                if ($portions > 0) $cost += $this->useComponents($recipe, $portions, $context);
            }
            DB::table('inventory_consumptions')->insert([
                'order_id' => $order->id, 'user_id' => $actor, 'cost_cents' => $cost, 'covered_lines' => $recipes->count(),
                'total_lines' => count($required), 'created_at' => now(),
            ]);
            OperationsAudit::record($actor, 'inventario.consumo', 'pedido', $order->id, ['costo' => $cost, 'recetas' => $recipes->count()]);
            return $query->first();
        });
    }

    public function recipeQuantities(Order $order): array
    {
        $required = [];
        foreach ($order->items as $line) {
            $id = $line['productId'];
            if (str_starts_with($id, 'menu-dia__')) {
                $parts = explode('__', $id);
                $keys = ['fondo:'.$parts[1], 'entrada:'.$parts[2]];
            } else {
                $keys = ['carta:'.$id];
            }
            foreach ($keys as $key) {
                $required[$key] = ($required[$key] ?? 0) + $line['quantity'];
            }
        }
        return $required;
    }

    private function portionCost(int $value, int $available, int $quantity): int
    {
        return $quantity === $available ? $value : (int) round($value * ($quantity / $available));
    }

    private function record(array $values): void
    {
        DB::table('inventory_movements')->insert([...$values, 'created_at' => now()]);
    }
}
