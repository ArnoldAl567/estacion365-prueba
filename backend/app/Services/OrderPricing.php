<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderPricing
{
    public function calculate(array $lines, string $method): array
    {
        $items = [];
        $subtotal = 0;
        $packaging = 0;
        $products = DB::table('menu_products')->where('active', true)->get()->keyBy('item_key');
        $prices = DB::table('menu_prices')->pluck('price_cents', 'type');

        foreach ($lines as $line) {
            $id = $line['productId'];
            $starter = null;
            $main = null;
            $menuType = null;
            if (str_starts_with($id, 'menu-dia__')) {
                $parts = explode('__', $id);
                $type = $parts[3] ?? '';
                $main = $products->get('fondo:'.($parts[1] ?? ''));
                $starter = $products->get('entrada:'.($parts[2] ?? ''));
                if (count($parts) !== 4 || ! $main || ! $starter || ! in_array($type, ['economico', 'ejecutivo'], true) || ! isset($prices[$type])) {
                    throw ValidationException::withMessages(['items' => 'Un menú o su entrada ya no están disponibles. Actualiza tu pedido.']);
                }
                $menuType = $type === 'economico' ? 'Económico' : 'Ejecutivo';
                $name = 'Menú '.$menuType.': '.$main->name;
                $unitPrice = (int) $prices[$type];
                $category = 'menu-dia';
            } else {
                $product = $products->get('carta:'.$id);
                if (! $product || $product->price_cents === null) {
                    throw ValidationException::withMessages(['items' => 'Un plato ya no está disponible. Actualiza tu pedido.']);
                }
                $name = $product->name;
                $unitPrice = (int) $product->price_cents;
                $category = $product->category_id;
            }
            $quantity = (int) $line['quantity'];
            $lineTotal = $unitPrice * $quantity;
            $subtotal += $lineTotal;
            if ($method !== 'table' && $category !== 'bebidas') {
                $packaging += 100 * $quantity;
            }
            $items[] = [
                'productId' => $id, 'name' => $name, 'quantity' => $quantity,
                'unitPriceCents' => $unitPrice, 'subtotalCents' => $lineTotal,
                'notes' => trim($line['notes'] ?? ''), 'categoryId' => $category,
                'main' => $main?->name, 'starter' => $starter?->name, 'menuType' => $menuType,
            ];
        }

        return ['items' => $items, 'subtotal_cents' => $subtotal, 'packaging_cents' => $packaging, 'total_cents' => $subtotal + $packaging];
    }
}
