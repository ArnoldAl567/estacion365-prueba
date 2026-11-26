<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class ProductionTickets
{
    public function sync(Order $order): void
    {
        DB::table('production_tickets')->where('order_id', $order->id)->delete();
        DB::table('production_tickets')->insert([
            'order_id' => $order->id, 'center' => 'kitchen', 'status' => 'pending',
            'items' => json_encode(array_values($order->items), JSON_THROW_ON_ERROR),
        ]);
    }

    public function bulkStatus(Order $order, string $target): void
    {
        $tickets = DB::table('production_tickets')->where('order_id', $order->id);
        match ($target) {
            'preparing' => $tickets->where('status', 'pending')->update(['status' => $target, 'started_at' => now()]),
            'ready' => $tickets->where('status', '!=', 'ready')->update(['status' => $target, 'ready_at' => now()]),
            'cancelled' => $tickets->update(['status' => $target]),
            default => null,
        };
    }

    public function advance(int $orderId, string $center, string $expected, int $revision, int $actor): Order
    {
        abort_unless($center === 'kitchen', 422, 'La preparación se gestiona únicamente en Cocina.');
        return DB::transaction(function () use ($orderId, $center, $expected, $revision, $actor) {
            app(InventoryLedger::class)->lock();
            $order = Order::lockForUpdate()->findOrFail($orderId);
            abort_if((int) $order->revision !== $revision, 409, 'El pedido fue editado. Revisa la comanda antes de continuar.');
            abort_unless(in_array($order->status, ['pending', 'preparing'], true), 409, 'El pedido ya cambió de estado.');
            $query = DB::table('production_tickets')->where('order_id', $orderId)->where('center', $center);
            $ticket = $query->first();
            abort_unless($ticket && $ticket->status === $expected, 409, 'La comanda ya cambió de estado.');
            app(DailyProduction::class)->start($order, $actor, json_decode($ticket->items, true, flags: JSON_THROW_ON_ERROR));
            $next = $expected === 'pending' ? 'preparing' : 'ready';
            $query->update(['status' => $next, ($next === 'preparing' ? 'started_at' : 'ready_at') => now()]);
            $status = DB::table('production_tickets')->where('order_id', $orderId)->where('status', '!=', 'ready')->exists() ? 'preparing' : 'ready';
            if ($order->status !== $status) {
                $order->update(['status' => $status]);
                $order->events()->create(['status' => $status, 'user_id' => $actor]);
            }
            OperationsAudit::record($actor, 'comanda.'.$next, 'pedido', $orderId, ['centro' => $center]);

            return $order;
        });
    }
}
