<?php

namespace App\Http\Controllers;

use App\Services\MenuCategoryLabels;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesReportController extends Controller
{
    private const PAID_LOCAL = "(order_payments.paid_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Lima'";

    public function index(Request $request): array
    {
        $filters = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'group' => 'required|in:summary,article,category',
            'start' => 'nullable|date_format:H:i',
            'end' => 'nullable|date_format:H:i',
            'category' => 'nullable|string|max:64',
            'staff' => 'nullable|integer|min:0',
            'attribution' => 'nullable|in:cashier,order',
        ]);
        $start = CarbonImmutable::parse($filters['from'], 'America/Lima')->startOfDay();
        $last = CarbonImmutable::parse($filters['to'], 'America/Lima')->startOfDay();
        abort_if($start->diffInDays($last) > 365, 422, 'El periodo puede incluir hasta 366 días.');
        abort_if(($filters['start'] ?? '00:00') > ($filters['end'] ?? '23:59'), 422, 'La hora final debe ser igual o posterior a la inicial.');
        $range = [$start->utc(), $last->addDay()->utc()];
        $group = $filters['group'];
        if ($group === 'summary') {
            return [...$filters, ...$this->summary($range)];
        }

        $paid = DB::table('order_payments')->join('orders', 'orders.id', '=', 'order_payments.order_id');
        $this->within($paid, 'order_payments.paid_at', $range);
        $this->filterSales($paid, $filters);
        $lines = $this->saleLines($paid, $group === 'category', $filters['category'] ?? null);
        $rows = DB::query()->fromSub(clone $lines, 'sale_lines')
            ->selectRaw('series, category, (array_agg(label order by paid_at desc, order_id desc))[1] as name,
                sum(quantity)::bigint as units, sum(amount_cents)::bigint as amount_cents')
            ->groupBy('series', 'category')->orderByDesc('amount_cents')->orderBy('name')->orderBy('series')->get();

        return [
            ...$filters,
            'rows' => $rows,
            'days' => $this->series($lines, $rows),
            'categories' => DB::table('menu_products')->whereNotNull('category_id')->distinct()->pluck('category_id')
                ->merge($rows->pluck('category'))->push('menu-dia')->unique()->sort()->values(),
            'staffOptions' => DB::table('users')->orderBy('name')->get(['id', 'name']),
            'categoryLabels' => MenuCategoryLabels::names(),
        ];
    }

    private function within(Builder $query, string $column, array $range): Builder
    {
        return $query->where($column, '>=', $range[0])->where($column, '<', $range[1]);
    }

    private function summary(array $range): array
    {
        $payments = $this->within(DB::table('order_payments'), 'paid_at', $range);
        $orders = $this->within(DB::table('orders'), 'created_at', $range);
        $expenses = $this->within(DB::table('cash_expenses'), 'created_at', $range);
        $day = 'date('.self::PAID_LOCAL.')';
        return [
            'days' => (clone $payments)->selectRaw($day.' as day, sum(amount_cents)::bigint as amount_cents, count(*) as orders')
                ->groupByRaw($day)->orderBy('day')->get(),
            'payments' => $payments->selectRaw('method, method_name as name, sum(amount_cents)::bigint as amount_cents, count(*) as orders')
                ->groupBy('method', 'method_name')->orderByDesc('amount_cents')->get(),
            'orders' => $orders->selectRaw('status, count(*) as count')->groupBy('status')->get(),
            'expenseTotal' => (int) $expenses->sum('amount_cents'),
        ];
    }

    private function filterSales(Builder $paid, array $filters): void
    {
        if (isset($filters['start']) || isset($filters['end'])) {
            $paid->whereRaw("to_char(".self::PAID_LOCAL.", 'HH24:MI') between ? and ?", [
                $filters['start'] ?? '00:00', $filters['end'] ?? '23:59',
            ]);
        }
        if (! isset($filters['staff'])) {
            return;
        }
        $actor = (int) $filters['staff'];
        if (($filters['attribution'] ?? 'cashier') === 'cashier') {
            $paid->where('order_payments.user_id', $actor);
        } elseif ($actor === 0) {
            $paid->where('orders.source', 'web');
        } else {
            $paid->where('orders.source', 'staff')->where(function (Builder $query) {
                $query->select('user_id')->from('order_events')->whereColumn('order_id', 'orders.id')
                    ->orderBy('id')->limit(1);
            }, '=', $actor);
        }
    }

    private function saleLines(Builder $paid, bool $categories, ?string $category): Builder
    {
        $categoryKey = "coalesce(nullif(line.item->>'categoryId', ''), 'sin-categoria')";
        $menuName = "concat('Menú ', coalesce(nullif(line.item->>'menuType', ''), 'del día'))";
        $label = $categories ? $categoryKey
            : "case when $categoryKey = 'menu-dia' then $menuName else line.item->>'name' end";
        $key = $categories ? $categoryKey
            : "case when $categoryKey = 'menu-dia' then concat('menu:', $menuName)
               else concat('plato:', line.item->>'productId', '|', $categoryKey) end";

        return $paid->crossJoin(DB::raw('lateral jsonb_array_elements(orders.items) as line(item)'))
            ->when($category, fn (Builder $query) => $query->whereRaw("$categoryKey = ?", [$category]))
            ->selectRaw('date('.self::PAID_LOCAL.") as day, order_payments.paid_at, orders.id as order_id,
                $categoryKey as category, $key as series, $label as label,
                (line.item->>'quantity')::integer as quantity, (line.item->>'subtotalCents')::bigint as amount_cents");
    }

    private function series(Builder $lines, Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }
        $leaders = $rows->take(5)->pluck('series')->all();
        $slots = implode(',', array_fill(0, count($leaders), '?'));
        $grouped = DB::query()->fromSub($lines, 'sale_lines')->selectRaw(
            "day, case when series in ($slots) then series else 'otros' end as series, amount_cents", $leaders,
        );
        return DB::query()->fromSub($grouped, 'grouped_lines')
            ->selectRaw('day, series, sum(amount_cents)::bigint as amount_cents')
            ->groupBy('day', 'series')->orderBy('day')->get();
    }
}
