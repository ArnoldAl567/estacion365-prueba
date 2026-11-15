<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class DemandReportController extends Controller
{
    public function __invoke(Request $request): array
    {
        $data = $request->validate([
            'from' => 'required_with:to|date_format:Y-m-d',
            'to' => 'required_with:from|date_format:Y-m-d|after_or_equal:from',
        ]);

        $today = CarbonImmutable::today('America/Lima');
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], 'America/Lima') : $today->subDays(6);
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], 'America/Lima') : $today;
        $rankings = ['carta' => [], 'fondos' => [], 'entradas' => []];
        $orderCount = 0;

        $orders = Order::query()
            ->where('created_at', '>=', $from->utc())
            ->where('created_at', '<', $to->addDay()->utc())
            ->where('status', '!=', 'cancelled')
            ->select(['id', 'items'])
            ->lazyById(250);

        foreach ($orders as $order) {
            $orderCount++;
            foreach ($order->items as $item) {
                $quantity = (int) $item['quantity'];
                if ($item['categoryId'] === 'menu-dia') {
                    // La clave del menú guarda el fondo y la entrada independientemente del tipo.
                    $parts = explode('__', $item['productId'], 4);
                    $this->countDish($rankings['fondos'], $parts[1] ?? null, $item['main'] ?? null, $quantity);
                    $this->countDish($rankings['entradas'], $parts[2] ?? null, $item['starter'] ?? null, $quantity);
                } elseif ($item['categoryId'] !== 'bebidas') {
                    $this->countDish($rankings['carta'], $item['productId'], $item['name'], $quantity);
                }
            }
        }

        foreach ($rankings as &$section) {
            usort($section, fn ($a, $b) => ($b['quantity'] <=> $a['quantity']) ?: strnatcasecmp($a['name'], $b['name']));
            $section = array_slice($section, 0, 10);
        }

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'orderCount' => $orderCount, 'rankings' => $rankings];
    }

    private function countDish(array &$section, ?string $id, ?string $name, int $quantity): void
    {
        if ($name === null || $name === '') {
            return;
        }

        $key = $id ? 'id:'.$id : 'name:'.$name;
        $section[$key] = ['key' => $key, 'name' => $name, 'quantity' => ($section[$key]['quantity'] ?? 0) + $quantity];
    }
}
