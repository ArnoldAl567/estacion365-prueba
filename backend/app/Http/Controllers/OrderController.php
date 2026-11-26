<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\MenuCategoryLabels;
use App\Services\OrderPricing;
use App\Services\OperationsAudit;
use App\Services\ProductionTickets;
use App\Services\SalonLayout;
use App\Services\DailyProduction;
use App\Services\InventoryLedger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(private readonly SalonLayout $salonLayout, private readonly ProductionTickets $production) {}

    public function catalog(): array
    {
        $items = DB::table('menu_products')->orderBy('sort_order')->get()->map(fn ($item) => [
            ...((array) $item), 'price' => $item->price_cents === null ? null : $item->price_cents / 100,
        ]);

        return ['items' => $items, 'prices' => DB::table('menu_prices')->get()->mapWithKeys(fn ($row) => [$row->type => $row->price_cents / 100]),
            'categoryLabels' => MenuCategoryLabels::names()];
    }

    public function store(Request $request, OrderPricing $pricing): JsonResponse
    {
        return $this->saveNew($request, $pricing, false);
    }

    public function storeFromStaff(Request $request, OrderPricing $pricing): JsonResponse
    {
        return $this->saveNew($request, $pricing, true);
    }

    private function validateDraft(Request $request, bool $staff, bool $editing = false): array
    {
        if (is_array($request->input('customer'))) {
            $customer = $request->input('customer');
            foreach (['name', 'address', 'reference', 'tableLabel'] as $field) {
                if (isset($customer[$field]) && is_string($customer[$field])) {
                    $value = trim($customer[$field]);
                    $customer[$field] = $value === '' ? null : $value;
                }
            }
            $request->merge(['customer' => $customer]);
        }
        $data = $request->validate([
            ...($editing ? ['expectedRevision' => 'required|integer|min:0'] : ['requestId' => 'required|uuid']),
            'expectedTotalCents' => 'required|integer|min:0',
            'customer' => 'required|array:name,phone,method,payment,address,reference'.($staff ? ',tableLabel,tableId' : ''),
            'customer.name' => ($staff ? 'nullable|required_unless:customer.method,table' : 'required').'|string|min:2|max:120',
            'customer.phone' => [
                ...($staff ? ['nullable', 'required_if:customer.method,delivery'] : ['required']),
                'string', 'regex:/^[0-9]{9}$/',
            ],
            'customer.method' => ['required', Rule::in(['pickup', 'delivery', 'table'])],
            'customer.payment' => [$staff ? 'nullable' : 'required', Rule::in(['yape', 'cash'])],
            'customer.tableLabel' => $staff ? 'exclude_unless:customer.method,table|required|string|max:40' : 'prohibited',
            'customer.tableId' => $staff ? 'exclude_unless:customer.method,table|nullable|uuid' : 'prohibited',
            'customer.address' => 'nullable|string|max:240|required_if:customer.method,delivery|min:6',
            'customer.reference' => 'nullable|string|max:160',
            'items' => 'required|array|min:1|max:100',
            'items.*.productId' => 'required|string|max:200',
            'items.*.quantity' => 'required|integer|min:1|max:99',
            'items.*.notes' => 'nullable|string|max:100',
            ...($staff ? ['items.*.modifiers' => 'nullable|array|list|max:8', 'items.*.modifiers.*' => 'required|string|max:40'] : []),
        ], [
            'customer.phone.regex' => 'Ingresa un teléfono de 9 dígitos.',
            'customer.phone.required_if' => 'Ingresa el teléfono del cliente para el delivery.',
            'customer.name.required_unless' => 'Ingresa el nombre del cliente.',
            'customer.address.required_if' => 'Completa la dirección de entrega.',
            'items.required' => 'Agrega platos a tu pedido.',
            'customer.tableLabel.required' => 'Indica la mesa del pedido.',
        ]);
        abort_if(array_sum(array_column($data['items'], 'quantity')) > 200, 422, 'El pedido admite hasta 200 unidades.');

        $data['items'] = array_map(fn ($line) => [
            'productId' => $line['productId'], 'quantity' => (int) $line['quantity'], 'notes' => trim($line['notes'] ?? ''),
            ...($staff ? ['modifiers' => array_values(array_unique(array_map('trim', $line['modifiers'] ?? [])))] : []),
        ], $data['items']);

        return $data;
    }

    private function customerAttributes(array $customer): array
    {
        return [
            'customer_name' => $customer['name'] ?? '', 'customer_phone' => $customer['phone'] ?? '',
            'delivery_method' => $customer['method'], 'payment_method' => $customer['payment'] ?? null,
            'address' => $customer['method'] === 'delivery' ? $customer['address'] : null,
            'reference' => $customer['method'] === 'delivery' ? ($customer['reference'] ?? '') : null,
            'table_label' => $customer['tableLabel'] ?? null,
        ];
    }

    private function saveNew(Request $request, OrderPricing $pricing, bool $staff): JsonResponse
    {
        $data = $this->validateDraft($request, $staff);
        $customer = $data['customer'];
        $lines = $data['items'];
        $hash = hash('sha256', json_encode([
            trim($customer['name'] ?? ''), $customer['phone'] ?? '', $customer['method'], $customer['payment'] ?? null,
            trim($customer['address'] ?? ''), trim($customer['reference'] ?? ''), $lines, $data['expectedTotalCents'],
            ...($staff ? ['staff', $customer['tableLabel'] ?? null] : []),
            ...(! empty($customer['tableId']) ? [$customer['tableId']] : []),
        ], JSON_THROW_ON_ERROR));

        $order = DB::transaction(function () use ($request, $data, $customer, $lines, $hash, $pricing, $staff) {
            app(InventoryLedger::class)->lock();
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$data['requestId']]);
            $existing = Order::where('request_id', $data['requestId'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'El pedido cambió. Revísalo y vuelve a confirmar.');

                return $existing;
            }
            if (! $staff && ! config('restaurant.open_24_hours')) {
                $hour = now('America/Lima')->hour;
                abort_if($hour < 9 || $hour >= 23, 422, 'Restaurante cerrado. Atendemos de 9:00 a. m. a 11:00 p. m.');
            }
            $amounts = $pricing->calculate($lines, $customer['method']);
            abort_unless($amounts['total_cents'] === (int) $data['expectedTotalCents'], 409, 'Los precios cambiaron. Revisa el total actualizado y vuelve a confirmar.');
            if ($staff) {
                $customer = $this->salonLayout->resolveTable($customer);
            }
            $order = Order::create([
                ...$amounts, 'request_id' => $data['requestId'], 'tracking_id' => (string) Str::uuid(), 'request_hash' => $hash,
                ...$this->customerAttributes($customer), 'source' => $staff ? 'staff' : 'web',
            ]);
            $order->events()->create(['status' => 'pending', 'user_id' => $staff ? $request->user()->id : null]);
            app(DailyProduction::class)->reserve($order);
            $this->production->sync($order);
            OperationsAudit::record($staff ? $request->user()->id : null, 'pedido.registrado', 'pedido', $order->id);

            return $order;
        });

        return response()->json(['order' => $staff ? $order->staffDetails() : $order->receipt()], $order->wasRecentlyCreated ? 201 : 200);
    }

    public function editFromStaff(Request $request, Order $order, OrderPricing $pricing): array
    {
        $data = $this->validateDraft($request, true, true);
        $saved = DB::transaction(function () use ($request, $order, $pricing, $data) {
            app(InventoryLedger::class)->lock();
            $customer = $this->salonLayout->resolveTable($data['customer']);
            $current = Order::lockForUpdate()->findOrFail($order->id);
            abort_unless($current->status === 'pending', 409, 'Solo se pueden editar pedidos pendientes.');
            abort_unless((int) $current->revision === (int) $data['expectedRevision'], 409, 'Otra persona editó este pedido. Cierra el formulario y abre la versión actualizada.');
            abort_if(DB::table('order_payments')->where('order_id', $current->id)->exists(), 409, 'El pedido ya fue cobrado.');
            $amounts = $pricing->calculate($data['items'], $data['customer']['method'], $current->items);
            abort_unless($amounts['total_cents'] === (int) $data['expectedTotalCents'], 409, 'Los precios cambiaron. Revisa el total actualizado.');
            $current->update([...$amounts, ...$this->customerAttributes($customer), 'revision' => $current->revision + 1]);
            app(DailyProduction::class)->reserve($current);
            $current->events()->create(['status' => 'pending', 'user_id' => $request->user()->id, 'reason' => 'Edición del pedido']);
            $this->production->sync($current);
            OperationsAudit::record($request->user()->id, 'pedido.editado', 'pedido', $current->id);

            return $current;
        });

        return ['order' => $saved->staffDetails()];
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
                    $query->where('customer_name', 'ilike', '%'.$search.'%')->orWhere('customer_phone', 'like', '%'.$search.'%')
                        ->orWhere('table_label', 'ilike', '%'.$search.'%');
                    if (ctype_digit(ltrim($search, '#'))) {
                        $query->orWhere('id', (int) ltrim($search, '#'));
                    }
                });
            }
            $page = $query->orderByDesc('id')->paginate(20);

            return ['orders' => $page->getCollection()->map(fn ($order) => $order->staffDetails()),
                'pagination' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()]];
        }

        $orders = $query->where(fn ($q) => $q->whereIn('status', ['pending', 'preparing', 'ready'])
            ->orWhere(fn ($open) => $open->where('status', 'delivered')->where('delivery_method', 'table')->whereNull('table_closed_at')))
            ->orderByDesc('id')->get();
        $tickets = $this->tickets($orders->modelKeys());
        $payments = DB::table('order_payments')->whereIn('order_id', $orders->modelKeys())->pluck('paid_at', 'order_id')
            ->map(fn ($date) => CarbonImmutable::parse($date, 'UTC')->toIso8601String());
        $start = CarbonImmutable::now('America/Lima')->startOfDay()->utc();
        $today = Order::where('created_at', '>=', $start)->where('status', '!=', 'cancelled')
            ->selectRaw('count(*) as count, coalesce(sum(total_cents), 0) as total')->first();

        return ['orders' => $orders->map(fn ($order) => [
            ...$order->staffDetails(), 'paidAt' => $payments[$order->id] ?? null,
            'tickets' => $tickets[$order->id] ?? [],
        ]),
            'summary' => ['todayCount' => (int) $today->count, 'todayTotalCents' => (int) $today->total]];
    }

    public function show(Order $order): array
    {
        $paid = DB::table('order_payments')->where('order_id', $order->id)->value('paid_at');
        return ['order' => [...$order->staffDetails(), 'paidAt' => $paid ? CarbonImmutable::parse($paid, 'UTC')->toIso8601String() : null,
            'tickets' => $this->tickets([$order->id])[$order->id] ?? [],
            'events' => $order->events()->with('user:id,name')->get()->map(fn ($event) => [
            'status' => $event->status, 'at' => $event->created_at->toIso8601String(), 'by' => $event->user?->name ?? 'Carta digital',
            'edited' => $event->status === 'pending' && $event->reason === 'Edición del pedido',
            'reason' => $event->reason,
        ])]];
    }

    private function tickets(array $ids): \Illuminate\Support\Collection
    {
        return DB::table('production_tickets')->join('production_centers', 'production_centers.code', '=', 'production_tickets.center')
            ->whereIn('order_id', $ids)
            ->select('production_tickets.*', 'production_centers.name', 'production_centers.printer')->orderBy('production_tickets.id')
            ->get()->groupBy('order_id')->map(fn ($tickets) => $tickets->map(fn ($ticket) => [
                'center' => $ticket->center, 'name' => $ticket->name, 'status' => $ticket->status, 'printer' => $ticket->printer,
                'items' => json_decode($ticket->items, true, flags: JSON_THROW_ON_ERROR),
            ])->values());
    }

    public function update(Request $request, Order $order): array
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['preparing', 'ready', 'delivered', 'cancelled'])],
            'expectedStatus' => 'required|string',
            'expectedRevision' => 'sometimes|integer|min:0',
            'reason' => 'required_if:status,cancelled|nullable|string|max:240',
        ], ['reason.required_if' => 'Escribe el motivo de la cancelación.']);
        $saved = DB::transaction(function () use ($request, $order, $data) {
            app(InventoryLedger::class)->lock();
            $current = Order::lockForUpdate()->findOrFail($order->id);
            abort_unless($current->status === $data['expectedStatus'], 409, 'Otra persona actualizó este pedido. La vista se actualizará.');
            abort_if(isset($data['expectedRevision']) && (int) $current->revision !== (int) $data['expectedRevision'], 409, 'El contenido del pedido cambió. Revísalo antes de continuar.');
            $next = ['pending' => 'preparing', 'preparing' => 'ready', 'ready' => 'delivered'];
            $cancelling = $data['status'] === 'cancelled';
            abort_if($request->user()->role === 'waiter' && in_array($data['status'], ['preparing', 'ready'], true),
                403, 'Cocina actualiza la preparación.');
            abort_if($cancelling && DB::table('order_payments')->where('order_id', $current->id)->exists(), 409, 'El pedido ya fue cobrado y requiere una devolución antes de cancelarse.');
            $allowed = $cancelling ? isset($next[$current->status]) : ($next[$current->status] ?? null) === $data['status'];
            abort_unless($allowed, 422, 'Este cambio de estado no está permitido.');
            if ($cancelling) app(DailyProduction::class)->cancel($current);
            else app(DailyProduction::class)->start($current, $request->user()->id);
            $current->status = $data['status'];
            $current->save();
            $this->production->bulkStatus($current, $data['status']);
            $current->events()->create([
                'status' => $data['status'],
                'user_id' => $request->user()->id,
                'reason' => $cancelling ? $data['reason'] : null,
            ]);
            OperationsAudit::record($request->user()->id, 'pedido.'.$data['status'], 'pedido', $current->id);

            return $current;
        });

        return ['order' => $saved->staffDetails()];
    }

    public function advanceTicket(Request $request, Order $order, string $center): array
    {
        $data = $request->validate([
            'expectedStatus' => ['required', Rule::in(['pending', 'preparing'])],
            'expectedRevision' => 'required|integer|min:0',
        ]);
        $saved = $this->production->advance($order->id, $center, $data['expectedStatus'], (int) $data['expectedRevision'], $request->user()->id);

        return ['order' => $saved->staffDetails()];
    }
}
