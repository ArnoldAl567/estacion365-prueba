<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderPricing;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function catalog(): array
    {
        $items = DB::table('menu_products')->orderBy('sort_order')->get()->map(fn ($item) => [
            ...((array) $item), 'price' => $item->price_cents === null ? null : $item->price_cents / 100,
        ]);

        return ['items' => $items, 'prices' => DB::table('menu_prices')->get()->mapWithKeys(fn ($row) => [$row->type => $row->price_cents / 100])];
    }

    public function store(Request $request, OrderPricing $pricing): JsonResponse
    {
        if (is_array($request->input('customer'))) {
            $customer = $request->input('customer');
            foreach (['name', 'address', 'reference'] as $field) {
                if (isset($customer[$field]) && is_string($customer[$field])) {
                    $customer[$field] = trim($customer[$field]);
                }
            }
            $request->merge(['customer' => $customer]);
        }
        $data = $request->validate([
            'requestId' => 'required|uuid',
            'expectedTotalCents' => 'required|integer|min:0',
            'customer' => 'required|array:name,phone,method,payment,address,reference',
            'customer.name' => 'required|string|min:2|max:120',
            'customer.phone' => ['required', 'string', 'regex:/^[0-9]{9}$/'],
            'customer.method' => ['required', Rule::in(['pickup', 'delivery', 'table'])],
            'customer.payment' => ['required', Rule::in(['yape', 'cash'])],
            'customer.address' => 'nullable|string|max:240|required_if:customer.method,delivery|min:6',
            'customer.reference' => 'nullable|string|max:160',
            'items' => 'required|array|min:1|max:100',
            'items.*.productId' => 'required|string|max:200',
            'items.*.quantity' => 'required|integer|min:1|max:99',
            'items.*.notes' => 'nullable|string|max:100',
        ], [
            'customer.phone.regex' => 'Ingresa un teléfono de 9 dígitos.',
            'customer.address.required_if' => 'Completa la dirección de entrega.',
            'items.required' => 'Agrega platos a tu pedido.',
        ]);
        abort_if(array_sum(array_column($data['items'], 'quantity')) > 200, 422, 'El pedido admite hasta 200 unidades.');
        $customer = $data['customer'];
        $lines = array_map(fn ($line) => ['productId' => $line['productId'], 'quantity' => (int) $line['quantity'], 'notes' => trim($line['notes'] ?? '')], $data['items']);
        $hash = hash('sha256', json_encode([
            trim($customer['name']), $customer['phone'], $customer['method'], $customer['payment'],
            trim($customer['address'] ?? ''), trim($customer['reference'] ?? ''), $lines, $data['expectedTotalCents'],
        ], JSON_THROW_ON_ERROR));

        $order = DB::transaction(function () use ($data, $customer, $lines, $hash, $pricing) {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$data['requestId']]);
            $existing = Order::where('request_id', $data['requestId'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'El pedido cambió. Revísalo y vuelve a confirmar.');

                return $existing;
            }
            if (! config('restaurant.open_24_hours')) {
                $hour = now('America/Lima')->hour;
                abort_if($hour < 9 || $hour >= 23, 422, 'Restaurante cerrado. Atendemos de 9:00 a. m. a 11:00 p. m.');
            }
            $amounts = $pricing->calculate($lines, $customer['method']);
            abort_unless($amounts['total_cents'] === (int) $data['expectedTotalCents'], 409, 'Los precios cambiaron. Revisa el total actualizado y vuelve a confirmar.');
            $order = Order::create([
                ...$amounts, 'request_id' => $data['requestId'], 'tracking_id' => (string) Str::uuid(), 'request_hash' => $hash,
                'customer_name' => trim($customer['name']), 'customer_phone' => $customer['phone'],
                'delivery_method' => $customer['method'], 'payment_method' => $customer['payment'],
                'address' => $customer['method'] === 'delivery' ? trim($customer['address']) : null,
                'reference' => $customer['method'] === 'delivery' ? trim($customer['reference'] ?? '') : null,
            ]);
            $order->events()->create(['status' => 'pending']);

            return $order;
        });

        return response()->json(['order' => $order->receipt()], $order->wasRecentlyCreated ? 201 : 200);
    }

    public function track(string $trackingId): array
    {
        return ['order' => Order::where('tracking_id', $trackingId)->firstOrFail()->receipt()];
    }

    public function index(Request $request): array
    {
        $data = $request->validate([
            'scope' => ['nullable', Rule::in(['active', 'history'])],
            'date' => 'nullable|date_format:Y-m-d', 'search' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
        ]);
        $query = Order::query();
        if (($data['scope'] ?? 'active') === 'history') {
            $query->whereIn('status', ['delivered', 'cancelled']);
            if (! empty($data['date'])) {
                $day = CarbonImmutable::parse($data['date'], 'America/Lima');
                $query->where('created_at', '>=', $day->startOfDay()->utc())->where('created_at', '<', $day->addDay()->startOfDay()->utc());
            }
            if (! empty($data['search'])) {
                $search = $data['search'];
                $query->where(function ($query) use ($search) {
                    $query->where('customer_name', 'ilike', '%'.$search.'%')->orWhere('customer_phone', 'like', '%'.$search.'%');
                    if (ctype_digit(ltrim($search, '#'))) {
                        $query->orWhere('id', (int) ltrim($search, '#'));
                    }
                });
            }
            $page = $query->orderByDesc('id')->paginate(20);

            return ['orders' => $page->getCollection()->map(fn ($order) => $order->staffDetails()),
                'pagination' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()]];
        }

        $orders = $query->whereIn('status', ['pending', 'preparing', 'ready'])->orderByDesc('id')->get();
        $start = CarbonImmutable::now('America/Lima')->startOfDay()->utc();
        $today = Order::where('created_at', '>=', $start)->where('status', '!=', 'cancelled')
            ->selectRaw('count(*) as count, coalesce(sum(total_cents), 0) as total')->first();

        return ['orders' => $orders->map(fn ($order) => $order->staffDetails()),
            'summary' => ['todayCount' => (int) $today->count, 'todayTotalCents' => (int) $today->total]];
    }

    public function show(Order $order): array
    {
        return ['order' => [...$order->staffDetails(), 'events' => $order->events()->with('user:id,name')->get()->map(fn ($event) => [
            'status' => $event->status, 'at' => $event->created_at->toIso8601String(), 'by' => $event->user?->name ?? 'Carta digital',
            'reason' => $event->reason,
        ])]];
    }

    public function update(Request $request, Order $order): array
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['preparing', 'ready', 'delivered', 'cancelled'])],
            'expectedStatus' => 'required|string',
            'reason' => 'required_if:status,cancelled|nullable|string|max:240',
        ], ['reason.required_if' => 'Escribe el motivo de la cancelación.']);
        $saved = DB::transaction(function () use ($request, $order, $data) {
            $current = Order::lockForUpdate()->findOrFail($order->id);
            abort_unless($current->status === $data['expectedStatus'], 409, 'Otra persona actualizó este pedido. La vista se actualizará.');
            $next = ['pending' => 'preparing', 'preparing' => 'ready', 'ready' => 'delivered'];
            $cancelling = $data['status'] === 'cancelled';
            $allowed = $cancelling ? isset($next[$current->status]) : ($next[$current->status] ?? null) === $data['status'];
            abort_unless($allowed, 422, 'Este cambio de estado no está permitido.');
            $current->status = $data['status'];
            $current->save();
            $current->events()->create([
                'status' => $data['status'],
                'user_id' => $request->user()->id,
                'reason' => $cancelling ? $data['reason'] : null,
            ]);

            return $current;
        });

        return ['order' => $saved->staffDetails()];
    }
}
