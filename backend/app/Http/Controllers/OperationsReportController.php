<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationsReportController extends Controller
{
    public function index(Request $request): array
    {
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        $start = CarbonImmutable::parse($data['from'], 'America/Lima')->startOfDay();
        $end = CarbonImmutable::parse($data['to'], 'America/Lima')->addDay()->startOfDay();
        $range = [$start->utc(), $end->utc()];
        $within = fn ($query, $column) => $query->where($column, '>=', $range[0])->where($column, '<', $range[1]);
        $payments = $within(DB::table('order_payments'), 'paid_at');
        $expenses = $within(DB::table('cash_expenses'), 'created_at');
        $matched = (clone $payments)->join('inventory_consumptions', 'inventory_consumptions.order_id', '=', 'order_payments.order_id')
            ->join('orders', 'orders.id', '=', 'order_payments.order_id')
            ->selectRaw('count(*) filter (where covered_lines = total_lines) as orders,
                coalesce(sum(cost_cents) filter (where covered_lines = total_lines), 0) as cost_cents,
                coalesce(sum(subtotal_cents) filter (where covered_lines = total_lines), 0) as sales_cents,
                count(*) filter (where covered_lines < total_lines) as partial')->first();
        return [
            ...$data,
            'orders' => $within(DB::table('orders'), 'created_at')->groupBy('delivery_method', 'status')
                ->selectRaw('delivery_method, status, count(*) as orders')->orderBy('delivery_method')->orderBy('status')->get(),
            'tickets' => $within(DB::table('production_tickets')->join('orders', 'orders.id', '=', 'production_tickets.order_id')
                ->join('production_centers', 'production_centers.code', '=', 'production_tickets.center'), 'orders.created_at')
                ->groupBy('production_centers.name', 'production_tickets.status')->selectRaw('production_centers.name, production_tickets.status, count(*) as orders')->get(),
            'sales' => (clone $payments)->groupByRaw("date((paid_at at time zone 'UTC') at time zone 'America/Lima')")
                ->selectRaw("date((paid_at at time zone 'UTC') at time zone 'America/Lima') as day, count(*) as orders, sum(amount_cents) as amount_cents")
                ->orderBy('day')->get(),
            'payments' => $payments->groupBy('method', 'method_name')->selectRaw('method_name, count(*) as orders, sum(amount_cents) as amount_cents')->get(),
            'expenses' => (clone $expenses)->latest('id')->paginate(30, ['id', 'description', 'amount_cents', 'created_at'], 'expensePage'),
            'expenseTotal' => (int) $expenses->sum('amount_cents'),
            'consumption' => $within(DB::table('inventory_movements')->join('inventory_items', 'inventory_items.id', '=', 'inventory_movements.item_id')
                ->join('inventory_units', 'inventory_units.id', '=', 'inventory_items.unit_id')->whereIn('type', ['produccion', 'consumo', 'merma', 'reversa_merma']), 'created_at')
                ->groupBy('inventory_items.name', 'type', 'symbol')->selectRaw('inventory_items.name, symbol, type, -sum(quantity_milli) as quantity_milli, -sum(cost_cents) as cost_cents')->get(),
            'production' => $within(DB::table('inventory_batches')->join('inventory_recipes', 'inventory_recipes.id', '=', 'inventory_batches.recipe_id')
                ->join('menu_products', 'menu_products.item_key', '=', 'inventory_recipes.product_key'), 'created_at')
                ->groupBy('menu_products.name')->selectRaw('menu_products.name, count(*) as batches, sum(portions) as portions, sum(cost_cents) as cost_cents')->get(),
            'foodCost' => array_map('intval', (array) $matched),
            'audit' => $within(DB::table('operations_audit')->leftJoin('users', 'users.id', '=', 'operations_audit.user_id'), 'operations_audit.created_at')
                ->latest('operations_audit.id')->paginate(35, ['operations_audit.id', 'operations_audit.action', 'operations_audit.entity',
                    'operations_audit.entity_id', 'operations_audit.created_at', 'users.name as actor'], 'auditPage'),
        ];
    }
}
