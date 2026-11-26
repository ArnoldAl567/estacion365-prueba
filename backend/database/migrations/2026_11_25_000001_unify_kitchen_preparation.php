<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve the original split in the audit trail before consolidating it.
        $centers = DB::table('production_centers')->where('code', '!=', 'kitchen')->get();
        if ($centers->isNotEmpty()) {
            DB::table('operations_audit')->insert([
                'action' => 'config.cocina_unificada', 'entity' => 'configuracion', 'entity_id' => 'cocina',
                'details' => json_encode(['centers' => $centers, 'routes' => DB::table('production_routes')->get()], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        }
        DB::table('production_centers')->updateOrInsert(['code' => 'kitchen'], ['name' => 'Cocina', 'active' => true]);
        $orders = DB::table('orders')->whereIn('id', DB::table('production_tickets')->where('center', '!=', 'kitchen')->select('order_id'));
        $orders->orderBy('id')->chunkById(100, function ($orders) {
            foreach ($orders as $order) {
                $tickets = DB::table('production_tickets')->where('order_id', $order->id)->orderBy('id')->get();
                DB::table('operations_audit')->insert([
                    'action' => 'comanda.cocina_unificada', 'entity' => 'pedido', 'entity_id' => (string) $order->id,
                    'details' => json_encode(['tickets' => $tickets], JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
                $items = $tickets->flatMap(fn ($ticket) => json_decode($ticket->items, true, flags: JSON_THROW_ON_ERROR))->values()->all();
                $status = $order->status === 'delivered' ? 'ready' : $order->status;
                DB::table('production_tickets')->updateOrInsert(['order_id' => $order->id, 'center' => 'kitchen'], [
                    'items' => json_encode($items, JSON_THROW_ON_ERROR), 'status' => $status,
                    'started_at' => $tickets->whereNotNull('started_at')->min('started_at'),
                    'ready_at' => $status === 'ready' ? $tickets->whereNotNull('ready_at')->max('ready_at') : null,
                ]);
                DB::table('production_tickets')->where('order_id', $order->id)->where('center', '!=', 'kitchen')->delete();
                // Reject stale preparation actions sent by tabs opened before this migration.
                DB::table('orders')->where('id', $order->id)->increment('revision');
            }
        });
        DB::table('production_routes')->update(['center' => 'kitchen']);
        DB::table('production_centers')->where('code', '!=', 'kitchen')->delete();
    }

    public function down(): void
    {
        throw new LogicException('La unificación de comandas conserva el detalle original en auditoría y no se revierte automáticamente.');
    }
};
