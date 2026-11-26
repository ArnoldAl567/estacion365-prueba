<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\InventoryLedger;
use App\Services\OperationsAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecordRemovalController extends Controller
{
    public function inventory(Request $request, string $kind, int $id): array
    {
        if ($kind === 'orders') {
            abort_unless($request->user()->role === 'admin', 403, 'Solo un administrador puede cancelar pedidos desde Inventario.');
            $request->validate(['expectedStatus' => 'required|string']);
            $request->merge(['status' => 'cancelled', 'reason' => 'Cancelación solicitada desde Inventario']);
            return app(OrderController::class)->update($request, Order::findOrFail($id));
        }
        return DB::transaction(function () use ($request, $kind, $id) {
            app(InventoryLedger::class)->lock();
            $table = match ($kind) {
                'items' => 'inventory_items', 'suppliers' => 'inventory_suppliers',
                'recipes' => 'inventory_recipes', 'purchases' => 'inventory_purchases', 'batches' => 'inventory_batches',
            };
            $record = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            abort_unless($record, 404, 'El registro ya no existe.');
            $used = match ($kind) {
                'items' => $record->stock_milli != 0 || $record->stock_value_cents != 0
                    || DB::table('inventory_incidents')->where('item_id', $id)->exists()
                    || DB::table('inventory_movements')->where('item_id', $id)->exists()
                    || DB::table('inventory_recipes')->whereJsonContains('components', [['item_id' => $id]])->exists()
                    || DB::table('inventory_portion_reservations')->whereJsonContains('components', [['item_id' => $id]])->exists(),
                'suppliers' => DB::table('inventory_purchases')->where('supplier_id', $id)->exists(),
                'recipes' => DB::table('inventory_batches')->where('recipe_id', $id)->exists()
                    || DB::table('inventory_daily_plans')->where('recipe_id', $id)->exists(),
                'purchases' => DB::table('inventory_movements')->where('purchase_id', $id)->exists(),
                'batches' => DB::table('inventory_movements')->where('batch_id', $id)->exists()
                    || DB::table('inventory_portion_reservations')->where('batch_id', $id)->exists(),
            };
            abort_if($used, 422, match ($kind) {
                'purchases' => 'Esta compra ya tiene movimientos de stock. No se puede borrar su historial; registra un movimiento de corrección con su motivo.',
                'batches' => 'Este lote ya tiene producción o consumo registrado. No se puede borrar su historial; registra una merma si corresponde.',
                default => 'No se puede eliminar: el registro tiene stock, movimientos o registros relacionados.',
            });
            DB::table($table)->where('id', $id)->delete();
            OperationsAudit::record($request->user()->id, 'inventario.'.$kind.'.eliminado', $kind, $id, (array) $record);
            return ['deleted' => true];
        });
    }

    public function settings(Request $request, string $kind, string $key): array
    {
        DB::transaction(function () use ($request, $kind, $key) {
            if ($kind === 'printers') {
                $row = DB::table('production_centers')->where('code', $key)->lockForUpdate()->first();
                abort_unless($row, 404);
                DB::table('production_centers')->where('code', $key)->update(['printer' => null]);
            } elseif ($kind === 'categories') {
                abort_if($key === 'sin-categoria', 422, 'Esta agrupación muestra productos sin categoría. Asigna una categoría a esos productos para dejar de mostrarla.');
                $products = DB::table('menu_products');
                $key === 'menu-dia' ? $products->where('kind', '!=', 'carta') : $products->where('category_id', $key);
                abort_if($products->exists(), 422, 'La categoría contiene productos. Reasígnalos antes de eliminarla.');
                DB::table('menu_category_labels')->where('category', $key)->delete();
                DB::table('production_routes')->where('category', $key)->delete();
            } else {
                $table = match ($kind) { 'centers' => 'production_centers', 'methods' => 'payment_methods', 'routes' => 'production_routes' };
                $column = $kind === 'routes' ? 'category' : 'code';
                abort_unless(DB::table($table)->where($column, $key)->lockForUpdate()->first(), 404);
                if ($kind === 'centers') {
                    abort_if($key === 'kitchen', 422, 'Cocina es el centro predeterminado y no se puede eliminar.');
                    abort_if(DB::table('production_routes')->where('center', $key)->exists()
                        || DB::table('production_tickets')->where('center', $key)->exists(), 422, 'El centro tiene categorías o comandas relacionadas. Puedes desactivarlo si ya no lo utilizas.');
                }
                if ($kind === 'methods') {
                    abort_if(in_array($key, ['cash', 'yape'], true), 422, 'Este medio está integrado en la toma de pedidos. Puedes desactivarlo desde Editar.');
                    abort_if(DB::table('order_payments')->where('method', $key)->exists(), 422, 'El medio tiene cobros registrados. Puedes desactivarlo para conservar el historial.');
                }
                DB::table($table)->where($column, $key)->delete();
            }
            OperationsAudit::record($request->user()->id, 'config.'.$kind.'.eliminado', $kind, $key);
        });
        return app(OperationsSettingsController::class)->show();
    }
}
