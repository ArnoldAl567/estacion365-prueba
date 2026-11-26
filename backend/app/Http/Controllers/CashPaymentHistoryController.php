<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashPaymentHistoryController extends Controller
{
    private function payments(): \Illuminate\Database\Query\Builder
    {
        return DB::table('order_payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->join('cash_shifts as s', 's.id', '=', 'p.shift_id')
            ->join('users as u', 'u.id', '=', 'p.user_id');
    }

    private function columns(): array
    {
        return ['p.id', 'p.order_id', 'p.amount_cents', 'p.method', 'p.method_name', 'p.paid_at',
            's.id as shift_id', 's.name as shift_name', 'u.name as cashier_name',
            'o.delivery_method', 'o.table_label', 'o.table_closed_at', 'o.status'];
    }

    public function index(Request $request): array
    {
        $f = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'table' => 'nullable|string|max:80', 'shift' => 'nullable|string|max:80',
            'method' => 'nullable|string|max:32', 'page' => 'nullable|integer|min:1']);
        abort_if(!empty($f['from']) && !empty($f['to']) && $f['to'] < $f['from'], 422, 'La fecha final debe ser igual o posterior a la inicial.');
        $query = $this->payments();
        if (!empty($f['from'])) {
            $query->where('p.paid_at', '>=', CarbonImmutable::parse($f['from'], 'America/Lima')->startOfDay()->utc());
        }
        if (!empty($f['to'])) {
            $query->where('p.paid_at', '<', CarbonImmutable::parse($f['to'], 'America/Lima')->addDay()->startOfDay()->utc());
        }
        foreach (['table' => 'o.table_label', 'shift' => 's.name'] as $key => $column) {
            if (isset($f[$key]) && trim($f[$key]) !== '') {
                $query->where($column, trim($f[$key]));
            }
        }
        if (!empty($f['method'])) {
            $query->where('p.method', $f['method']);
        }
        return [
            'total_cents' => (int) (clone $query)->sum('p.amount_cents'),
            'records' => $query->orderByDesc('p.paid_at')->orderByDesc('p.id')->paginate(30, $this->columns()),
            // Options come from the whole history, independently of the active filters or page.
            'tables' => $this->payments()->where('o.delivery_method', 'table')->whereNotNull('o.table_label')
                ->where('o.table_label', '!=', '')->distinct()->orderBy('o.table_label')->pluck('o.table_label'),
            'shifts' => $this->payments()->distinct()->orderBy('s.name')->pluck('s.name'),
            // Include methods used historically even if they were subsequently disabled.
            'methods' => DB::table('order_payments')->select('method', 'method_name')->distinct()->orderBy('method_name')->get(),
        ];
    }

    public function show(int $id): array
    {
        $payment = $this->payments()->where('p.id', $id)->first($this->columns());
        abort_unless($payment, 404, 'No se encontró el cobro.');
        return ['payment' => $payment, 'order' => Order::findOrFail($payment->order_id)->staffDetails()];
    }
}
