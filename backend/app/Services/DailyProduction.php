<?php

namespace App\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DailyProduction
{
    public function __construct(private readonly InventoryLedger $ledger) {}

    public function overview(string $day): array
    {
        $plans = DB::table('inventory_daily_plans as p')->join('inventory_recipes as r', 'r.id', '=', 'p.recipe_id')
            ->join('menu_products as m', 'm.item_key', '=', 'r.product_key')->where('p.day', $day)
            ->orderBy('m.name')->get(['p.*', 'r.product_key', 'm.name']);
        if ($plans->isEmpty()) return [];
        $start = CarbonImmutable::parse($day, 'America/Lima')->startOfDay()->utc();
        $batches = DB::table('inventory_batches')->whereIn('recipe_id', $plans->pluck('recipe_id'))
            ->where('created_at', '>=', $start)->where('created_at', '<', $start->addDay())
            ->groupBy('recipe_id')->selectRaw('recipe_id, sum(portions) as produced, sum(remaining) as remaining')->get()->keyBy('recipe_id');
        $usage = DB::table('inventory_portion_reservations')->whereIn('plan_id', $plans->pluck('id'))
            ->groupBy('plan_id')->selectRaw("plan_id,
                sum(portions) filter (where state = 'reserved') as reserved,
                sum(portions) filter (where state = 'reserved' and batch_id is not null) as batch_reserved,
                sum(portions) filter (where state != 'released' and batch_id is null) as direct_used,
                sum(portions) filter (where state = 'consumed') as consumed,
                sum(portions) filter (where state = 'wasted') as wasted")->get()->keyBy('plan_id');
        return $plans->map(function ($plan) use ($batches, $usage) {
            $batch = $batches->get($plan->recipe_id);
            $used = $usage->get($plan->id);
            return [...(array) $plan, 'produced_portions' => (int) ($batch->produced ?? 0),
                'reserved' => (int) ($used->reserved ?? 0), 'consumed' => (int) ($used->consumed ?? 0),
                'wasted' => (int) ($used->wasted ?? 0),
                'available' => max(0, $plan->direct_portions - ($used->direct_used ?? 0))
                    + max(0, ($batch->remaining ?? 0) - ($used->batch_reserved ?? 0))];
        })->all();
    }

    private function reservations(int $plan)
    {
        return DB::table('inventory_portion_reservations')->where('plan_id', $plan);
    }

    private function batches(object $plan)
    {
        // Daily production is explicit: yesterday's food is not automatically offered again.
        $start = CarbonImmutable::parse($plan->day, 'America/Lima')->startOfDay()->utc();
        return DB::table('inventory_batches')->where('recipe_id', $plan->recipe_id)
            ->where('created_at', '>=', $start)->where('created_at', '<', $start->addDay())
            ->orderBy('id')->get();
    }

    public function reserve(Order $order): void
    {
        $this->ledger->lock();
        // Only pending drafts can be replaced. Released rows are retained for traceability.
        DB::table('inventory_portion_reservations')->where('order_id', $order->id)->where('state', 'reserved')
            ->update(['state' => 'released', 'updated_at' => now()]);
        if (!config('restaurant.daily_availability')) return;
        $day = $order->created_at->timezone('America/Lima')->toDateString();
        $required = $this->ledger->recipeQuantities($order);
        $plans = DB::table('inventory_daily_plans as p')->join('inventory_recipes as r', 'r.id', '=', 'p.recipe_id')
            ->join('menu_products as m', 'm.item_key', '=', 'r.product_key')
            ->where('p.day', $day)->whereIn('r.product_key', array_keys($required))
            ->get(['p.*', 'r.components', 'r.product_key', 'm.name']);
        $controlled = DB::table('inventory_recipes as r')->join('menu_products as m', 'm.item_key', '=', 'r.product_key')
            ->whereIn('r.product_key', array_keys($required))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('inventory_daily_plans as p')
                ->whereColumn('p.recipe_id', 'r.id')->where('p.day', '<=', $day))
            ->get(['r.product_key', 'm.name']);
        foreach ($controlled as $product) {
            abort_unless($plans->contains('product_key', $product->product_key), 422,
                'No hay disponibilidad planificada para '.$product->name.' en este día.');
        }
        foreach ($plans as $plan) {
            $remaining = $required[$plan->product_key];
            foreach ($this->batches($plan) as $batch) {
                $held = DB::table('inventory_portion_reservations')->where('batch_id', $batch->id)->where('state', 'reserved')->sum('portions');
                $take = min($remaining, max(0, $batch->remaining - $held));
                if ($take) $this->insertReservation($plan, $order->id, $take, $batch->id);
                $remaining -= $take;
                if (!$remaining) break;
            }
            $used = $this->reservations($plan->id)->whereNull('batch_id')->where('state', '!=', 'released')->sum('portions');
            abort_if($remaining > max(0, $plan->direct_portions - $used), 422,
                'No quedan suficientes porciones de '.$plan->name.'. Revisa la disponibilidad del día.');
            if ($remaining) $this->insertReservation($plan, $order->id, $remaining, null);
        }
    }

    private function insertReservation(object $plan, int $order, int $portions, ?int $batch): void
    {
        DB::table('inventory_portion_reservations')->insert(['plan_id' => $plan->id, 'order_id' => $order,
            'batch_id' => $batch, 'portions' => $portions, 'components' => $plan->components,
            'state' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function start(Order $order, int $actor, ?array $items = null): void
    {
        $this->ledger->lock();
        if (!config('restaurant.daily_availability')) {
            DB::table('inventory_portion_reservations')->where('order_id', $order->id)->where('state', 'reserved')
                ->update(['state' => 'released', 'updated_at' => now()]);
            return;
        }
        $query = DB::table('inventory_portion_reservations')->where('order_id', $order->id)->where('state', 'reserved');
        if ($items !== null) {
            $areaOrder = clone $order;
            $areaOrder->items = $items;
            $keys = array_keys($this->ledger->recipeQuantities($areaOrder));
            $query->whereIn('plan_id', DB::table('inventory_daily_plans')->whereIn('recipe_id',
                DB::table('inventory_recipes')->whereIn('product_key', $keys)->select('id'))->select('id'));
        }
        foreach ($query->get() as $row) {
            $context = ['order_id' => $order->id, 'user_id' => $actor, 'type' => 'consumo'];
            $cost = $row->batch_id
                ? $this->ledger->useBatch(DB::table('inventory_batches')->where('id', $row->batch_id)->first(), $row->portions, $context)
                : $this->ledger->useComponents($row, $row->portions, $context);
            DB::table('inventory_portion_reservations')->where('id', $row->id)
                ->update(['state' => 'consumed', 'cost_cents' => $cost, 'updated_at' => now()]);
        }
    }

    public function cancel(Order $order): void
    {
        $this->ledger->lock();
        $query = DB::table('inventory_portion_reservations')->where('order_id', $order->id);
        (clone $query)->where('state', 'reserved')->update(['state' => 'released', 'updated_at' => now()]);
        (clone $query)->where('state', 'consumed')->update(['state' => 'wasted', 'updated_at' => now()]);
    }
}
