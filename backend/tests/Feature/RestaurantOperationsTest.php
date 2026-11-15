<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RestaurantOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'estacion_operaciones_test') {
            throw new \RuntimeException('Las pruebas solo pueden ejecutarse en estacion_operaciones_test.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-07 16:00:00 UTC');
        CarbonImmutable::setTestNow('2026-11-07 16:00:00 UTC');
        foreach ([
            ['carta:arroz', 'arroz', 'carta', 'marinos', 'Arroz con mariscos', 2000],
            ['carta:chicha', 'chicha', 'carta', 'bebidas', 'Chicha', 500],
            ['fondo:tallarin', 'tallarin', 'fondo', null, 'Tallarín verde', null],
            ['entrada:ceviche', 'ceviche', 'entrada', null, 'Ceviche', null],
            ['entrada:sopa', 'sopa', 'entrada', null, 'Sopa de sémola', null],
        ] as [$key, $source, $kind, $category, $name, $price]) {
            DB::table('menu_products')->insert(['item_key' => $key, 'source_id' => $source, 'kind' => $kind, 'category_id' => $category, 'name' => $name, 'price_cents' => $price]);
        }
        DB::table('menu_prices')->insert([['type' => 'economico', 'price_cents' => 1200], ['type' => 'ejecutivo', 'price_cents' => 1400]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function payload(): array
    {
        return [
            'requestId' => (string) Str::uuid(), 'expectedTotalCents' => 4700,
            'customer' => ['name' => 'Cliente de prueba', 'phone' => '987654321', 'method' => 'pickup', 'payment' => 'cash', 'address' => '', 'reference' => ''],
            'items' => [
                ['productId' => 'arroz', 'quantity' => 2, 'notes' => 'Sin picante', 'price' => 0.01],
                ['productId' => 'chicha', 'quantity' => 1, 'notes' => ''],
            ],
        ];
    }

    private function staff(string $role = 'admin'): User
    {
        $username = $role.'-'.Str::lower(Str::random(5));

        return User::create(['name' => 'Equipo de prueba', 'username' => $username, 'email' => $username.'@local.estacion365', 'role' => $role, 'password' => 'Prueba-local-365!', 'active' => true]);
    }

    public function test_web_order_uses_database_prices_and_records_packaging_and_notes(): void
    {
        $response = $this->postJson('/local-api/orders', $this->payload())->assertCreated();
        $response->assertJsonPath('order.subtotalCents', 4500)->assertJsonPath('order.packagingCents', 200)
            ->assertJsonPath('order.totalCents', 4700)->assertJsonPath('order.items.0.unitPriceCents', 2000)
            ->assertJsonPath('order.items.0.notes', 'Sin picante')->assertJsonPath('order.status', 'pending');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_events', 1);
        $this->getJson('/local-api/tracking/'.$response->json('order.trackingId'))->assertOk()
            ->assertJsonMissingPath('order.customerPhone')->assertJsonMissingPath('order.customerName');
    }

    public function test_retries_are_idempotent_and_modified_retries_are_rejected(): void
    {
        $payload = $this->payload();
        $first = $this->postJson('/local-api/orders', $payload)->assertCreated()->json('order.id');
        $this->postJson('/local-api/orders', $payload)->assertOk()->assertJsonPath('order.id', $first);
        $payload['customer']['name'] = 'Otro nombre';
        $this->postJson('/local-api/orders', $payload)->assertConflict();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_price_changes_and_unavailable_products_do_not_create_orders(): void
    {
        $payload = $this->payload();
        $payload['expectedTotalCents'] = 1;
        $this->postJson('/local-api/orders', $payload)->assertConflict();
        DB::table('menu_products')->where('source_id', 'arroz')->update(['active' => false]);
        $this->postJson('/local-api/orders', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_two_daily_menus_keep_individual_starters_and_types(): void
    {
        $payload = $this->payload();
        $payload['items'] = [
            ['productId' => 'menu-dia__tallarin__ceviche__economico', 'quantity' => 1, 'notes' => 'Sin ají'],
            ['productId' => 'menu-dia__tallarin__sopa__ejecutivo', 'quantity' => 1, 'notes' => ''],
        ];
        $payload['expectedTotalCents'] = 2800;
        $this->postJson('/local-api/orders', $payload)->assertCreated()
            ->assertJsonPath('order.items.0.starter', 'Ceviche')->assertJsonPath('order.items.0.menuType', 'Económico')
            ->assertJsonPath('order.items.1.starter', 'Sopa de sémola')->assertJsonPath('order.items.1.menuType', 'Ejecutivo')
            ->assertJsonPath('order.packagingCents', 200)->assertJsonPath('order.totalCents', 2800);
    }

    public function test_table_orders_have_no_packaging_and_delivery_requires_an_address(): void
    {
        $payload = $this->payload();
        $payload['customer']['method'] = 'table';
        $payload['expectedTotalCents'] = 4500;
        $this->postJson('/local-api/orders', $payload)->assertCreated()->assertJsonPath('order.packagingCents', 0);
        $payload = $this->payload();
        $payload['customer']['method'] = 'delivery';
        $this->postJson('/local-api/orders', $payload)->assertUnprocessable();
        $payload['customer']['address'] = 'Av. Los Pinos 461';
        $this->postJson('/local-api/orders', $payload)->assertCreated();
    }

    public function test_server_accepts_orders_around_the_clock_and_validates_phone_numbers(): void
    {
        $payload = $this->payload();
        $payload['customer']['phone'] = '123';
        $this->postJson('/local-api/orders', $payload)->assertUnprocessable();
        Carbon::setTestNow('2026-11-07 13:59:00 UTC');
        $this->postJson('/local-api/orders', $this->payload())->assertCreated();
        Carbon::setTestNow('2026-11-08 04:30:00 UTC');
        $this->postJson('/local-api/orders', $this->payload())->assertCreated();
        config(['restaurant.open_24_hours' => false]);
        $this->postJson('/local-api/orders', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_staff_permissions_transitions_and_history(): void
    {
        $this->getJson('/local-api/orders')->assertUnauthorized();
        $id = $this->postJson('/local-api/orders', $this->payload())->assertCreated()->json('order.id');
        $kitchen = $this->staff('kitchen');
        $this->actingAs($kitchen)->getJson('/local-api/orders')->assertOk()->assertJsonCount(1, 'orders');
        $this->getJson('/local-api/users')->assertForbidden();
        $this->postJson('/local-api/users', [])->assertForbidden();
        $this->patchJson('/local-api/orders/'.$id, ['status' => 'ready', 'expectedStatus' => 'pending'])->assertUnprocessable();
        $this->patchJson('/local-api/orders/'.$id, ['status' => 'preparing', 'expectedStatus' => 'pending'])->assertOk();
        $this->patchJson('/local-api/orders/'.$id, ['status' => 'preparing', 'expectedStatus' => 'pending'])->assertConflict();
        $this->patchJson('/local-api/orders/'.$id, ['status' => 'ready', 'expectedStatus' => 'preparing'])->assertOk();
        $this->patchJson('/local-api/orders/'.$id, ['status' => 'delivered', 'expectedStatus' => 'ready'])->assertOk();
        $this->patchJson('/local-api/orders/'.$id, ['status' => 'cancelled', 'expectedStatus' => 'delivered', 'reason' => 'Solicitud del cliente'])->assertUnprocessable();
        $this->getJson('/local-api/orders')->assertJsonCount(0, 'orders');
        $this->getJson('/local-api/orders?scope=history&date=2026-11-07&search=Cliente')->assertOk()->assertJsonCount(1, 'orders');
        $this->getJson('/local-api/orders/'.$id)->assertOk()->assertJsonCount(4, 'order.events');
        DB::table('menu_products')->update(['price_cents' => 9999]);
        $this->assertEquals(4700, Order::find($id)->total_cents);
    }

    public function test_salon_orders_share_the_kitchen_feed_and_history(): void
    {
        $payload = $this->payload();
        $payload['customer'] = ['method' => 'table'];
        $payload['expectedTotalCents'] = 4500;
        $payload['items'][0]['modifiers'] = ['Sin cebolla', 'Salsa aparte'];
        $this->postJson('/local-api/staff-orders', $payload)->assertUnauthorized();
        $this->actingAs($this->staff('kitchen'))->postJson('/local-api/staff-orders', $payload)->assertForbidden();
        $this->postJson('/local-api/order-modifiers', ['name' => 'Salsa aparte'])->assertForbidden();
        $this->actingAs($this->staff())->postJson('/local-api/staff-orders', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('customer.tableLabel');

        $modifier = $this->postJson('/local-api/order-modifiers', ['name' => 'Salsa aparte'])->assertOk()->json('modifiers.0.id');
        $this->postJson('/local-api/order-modifiers', ['name' => 'salsa aparte'])->assertConflict();
        $payload['customer']['tableLabel'] = 'Terraza 4';
        $order = $this->postJson('/local-api/staff-orders', $payload)->assertCreated()
            ->assertJsonPath('order.tableLabel', 'Terraza 4')->assertJsonPath('order.source', 'staff')
            ->assertJsonPath('order.payment', null)->assertJsonPath('order.packagingCents', 0)
            ->assertJsonPath('order.items.0.modifiers.1', 'Salsa aparte')->json('order');
        $this->postJson('/local-api/staff-orders', $payload)->assertOk()->assertJsonPath('order.id', $order['id']);
        $this->assertDatabaseCount('orders', 1);
        $this->deleteJson('/local-api/order-modifiers/'.$modifier)->assertNoContent();
        $this->getJson('/local-api/order-modifiers')->assertJsonCount(0, 'modifiers');

        $this->actingAs($this->staff('kitchen'))->getJson('/local-api/orders')
            ->assertJsonPath('orders.0.id', $order['id'])->assertJsonPath('orders.0.tableLabel', 'Terraza 4')
            ->assertJsonPath('orders.0.items.0.modifiers.1', 'Salsa aparte');
        $this->patchJson('/local-api/orders/'.$order['id'], [
            'status' => 'cancelled', 'expectedStatus' => 'pending', 'reason' => 'Solicitud del cliente',
        ])->assertOk();
        $this->getJson('/local-api/orders?scope=history&search=Terraza')
            ->assertJsonPath('orders.0.id', $order['id'])->assertJsonPath('orders.0.status', 'cancelled');
    }

    public function test_salon_edits_preserve_booked_prices_and_reject_stale_changes(): void
    {
        $payload = $this->payload();
        $payload['customer'] = ['method' => 'delivery', 'name' => 'Cliente local'];
        $this->actingAs($this->staff())->postJson('/local-api/staff-orders', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['customer.address', 'customer.phone']);
        $payload['customer'] += ['address' => 'Av. Los Pinos 461', 'phone' => '987654321'];
        $order = $this->postJson('/local-api/staff-orders', $payload)->assertCreated()->json('order');
        $path = '/local-api/staff-orders/'.$order['id'];
        $payload['expectedRevision'] = 0;
        $payload['items'][0]['quantity'] = 1;
        $payload['expectedTotalCents'] = 2600;
        DB::table('menu_products')->where('item_key', 'carta:arroz')->update(['price_cents' => 2500, 'active' => false]);
        $this->patchJson($path, $payload)->assertOk()->assertJsonPath('order.revision', 1)
            ->assertJsonPath('order.items.0.unitPriceCents', 2000);
        $this->patchJson($path, $payload)->assertConflict();
        $this->getJson('/local-api/orders/'.$order['id'])->assertJsonPath('order.events.1.edited', true);
        $payload['expectedRevision'] = 1;
        $payload['items'][0]['quantity'] = 2;
        $payload['expectedTotalCents'] = 4700;
        $this->patchJson($path, $payload)->assertUnprocessable();
        $status = ['status' => 'preparing', 'expectedStatus' => 'pending'];
        $this->patchJson('/local-api/orders/'.$order['id'], $status + ['expectedRevision' => 0])->assertConflict();
        $this->patchJson('/local-api/orders/'.$order['id'], $status + ['expectedRevision' => 1])->assertOk();
        $this->patchJson($path, $payload)->assertConflict();
    }

    public function test_salon_configuration_renames_active_tables_and_protects_occupied_tables(): void
    {
        $this->getJson('/local-api/salon-layout')->assertUnauthorized();
        $this->actingAs($this->staff('kitchen'))->getJson('/local-api/salon-layout')->assertForbidden();
        $this->actingAs($this->staff());
        $initial = $this->getJson('/local-api/salon-layout')->assertOk()->assertJsonCount(2, 'zones')->json();
        $order = $this->payload();
        $order['expectedTotalCents'] = 4500;
        $order['customer'] = ['method' => 'table', 'tableLabel' => '1'];
        $id = $this->postJson('/local-api/staff-orders', $order)->assertCreated()->json('order.id');

        $config = $initial;
        $config['zones'][0]['name'] = 'Terraza';
        $config['zones'][0]['tables'][0]['label'] = 'Ventana 1';
        $config['zones'][] = ['id' => (string) Str::uuid(), 'name' => 'Patio', 'tables' => []];
        $this->putJson('/local-api/salon-layout', $config)->assertOk()
            ->assertJsonPath('revision', 1)->assertJsonPath('zones.0.name', 'Terraza')->assertJsonCount(3, 'zones');
        $this->getJson('/local-api/orders/'.$id)->assertJsonPath('order.tableLabel', 'Ventana 1')
            ->assertJsonPath('order.revision', 1);
        $this->putJson('/local-api/salon-layout', $initial)->assertConflict();

        $order['requestId'] = (string) Str::uuid();
        $order['customer']['tableId'] = $initial['zones'][0]['tables'][0]['id'];
        $this->postJson('/local-api/staff-orders', $order)->assertCreated()->assertJsonPath('order.tableLabel', 'Ventana 1');
        $config['revision'] = 1;
        array_shift($config['zones'][0]['tables']);
        $this->putJson('/local-api/salon-layout', $config)->assertConflict();
        $this->getJson('/local-api/salon-layout')->assertJsonPath('zones.0.tables.0.label', 'Ventana 1');
    }

    public function test_initial_admin_setup_only_runs_once_and_user_access_can_be_revoked(): void
    {
        $setup = ['name' => 'Propietario', 'username' => 'Estacion365', 'password' => 'Prueba-local-365!', 'password_confirmation' => 'Prueba-local-365!'];
        $this->postJson('/local-api/setup', $setup)->assertOk()->assertJsonPath('user.role', 'admin');
        $this->postJson('/local-api/setup', $setup)->assertConflict();
        $response = $this->postJson('/local-api/users', ['name' => 'Cocinero', 'username' => 'cocina', 'password' => 'Prueba-cocina-365!', 'role' => 'kitchen'])->assertOk();
        $id = $response->json('user.id');
        $this->patchJson('/local-api/users/'.$id, ['active' => false])->assertOk();
        $this->postJson('/local-api/logout')->assertOk();
        $this->postJson('/local-api/login', ['username' => 'cocina', 'password' => 'Prueba-cocina-365!'])->assertUnprocessable();
        $this->getJson('/local-api/orders')->assertUnauthorized();
    }

    public function test_staff_can_cancel_each_active_status_and_keep_an_audit_trail(): void
    {
        $orders = [];
        foreach (['pending', 'preparing', 'ready'] as $status) {
            $orders[$status] = $this->postJson('/local-api/orders', $this->payload())->assertCreated()->json('order');
        }
        $cancel = ['status' => 'cancelled', 'reason' => 'Solicitud del cliente'];
        $this->patchJson('/local-api/orders/'.$orders['pending']['id'], $cancel + ['expectedStatus' => 'pending'])->assertUnauthorized();

        $staff = $this->staff('kitchen');
        $this->actingAs($staff);
        foreach ($orders as $status => $order) {
            $url = '/local-api/orders/'.$order['id'];
            if ($status !== 'pending') {
                $this->patchJson($url, ['status' => 'preparing', 'expectedStatus' => 'pending'])->assertOk();
            }
            if ($status === 'ready') {
                $this->patchJson($url, ['status' => 'ready', 'expectedStatus' => 'preparing'])->assertOk();
            }
            $this->patchJson($url, ['status' => 'cancelled', 'expectedStatus' => $status])->assertUnprocessable();
            $this->patchJson($url, $cancel + ['expectedStatus' => 'delivered'])->assertConflict();
            $this->patchJson($url, $cancel + ['expectedStatus' => $status])->assertOk()->assertJsonPath('order.status', 'cancelled');
            $this->patchJson($url, $cancel + ['expectedStatus' => 'cancelled'])->assertUnprocessable();

            $this->assertDatabaseHas('order_events', ['order_id' => $order['id'], 'status' => 'cancelled', 'user_id' => $staff->id, 'reason' => $cancel['reason']]);
            $this->getJson($url)->assertOk()->assertJsonFragment(['by' => $staff->name, 'reason' => $cancel['reason'], 'status' => 'cancelled']);
            $this->getJson('/local-api/tracking/'.$order['trackingId'])->assertOk()
                ->assertJsonPath('order.status', 'cancelled')->assertJsonMissingPath('order.events');
        }

        $this->getJson('/local-api/orders')->assertOk()->assertJsonCount(0, 'orders')
            ->assertJsonPath('summary.todayCount', 0)->assertJsonPath('summary.todayTotalCents', 0);
        $this->getJson('/local-api/orders?scope=history')->assertOk()->assertJsonCount(3, 'orders');
        $this->assertDatabaseCount('orders', 3);
    }

    public function test_dish_demand_ranks_units_by_section_and_uses_the_lima_date_range(): void
    {
        $url = '/local-api/reports/demand?from=2026-11-07&to=2026-11-07';
        $this->getJson($url)->assertUnauthorized();

        $carta = $this->postJson('/local-api/orders', $this->payload())->assertCreated()->json('order.id');
        Order::whereKey($carta)->update(['status' => 'delivered', 'created_at' => '2026-11-08 04:59:59']);

        $menu = $this->payload();
        $menu['items'] = [
            ['productId' => 'menu-dia__tallarin__ceviche__economico', 'quantity' => 2],
            ['productId' => 'menu-dia__tallarin__sopa__ejecutivo', 'quantity' => 1],
        ];
        $menu['expectedTotalCents'] = 4100;
        $this->postJson('/local-api/orders', $menu)->assertCreated();

        $cancelled = $this->postJson('/local-api/orders', $this->payload())->assertCreated()->json('order.id');
        Order::whereKey($cancelled)->update(['status' => 'cancelled']);
        $nextDay = $this->postJson('/local-api/orders', $this->payload())->assertCreated()->json('order.id');
        Order::whereKey($nextDay)->update(['created_at' => '2026-11-08 05:00:00']);

        $this->actingAs($this->staff('kitchen'))->getJson($url)->assertForbidden();
        $this->actingAs($this->staff())->getJson($url)->assertOk()
            ->assertJsonPath('orderCount', 2)
            ->assertJsonCount(1, 'rankings.carta')
            ->assertJsonPath('rankings.carta.0.name', 'Arroz con mariscos')
            ->assertJsonPath('rankings.carta.0.quantity', 2)
            ->assertJsonCount(1, 'rankings.fondos')
            ->assertJsonPath('rankings.fondos.0.name', 'Tallarín verde')
            ->assertJsonPath('rankings.fondos.0.quantity', 3)
            ->assertJsonCount(2, 'rankings.entradas')
            ->assertJsonPath('rankings.entradas.0.name', 'Ceviche')
            ->assertJsonPath('rankings.entradas.0.quantity', 2)
            ->assertJsonPath('rankings.entradas.1.quantity', 1);

        $this->getJson('/local-api/reports/demand')->assertOk()
            ->assertJsonPath('from', '2026-11-01')->assertJsonPath('to', '2026-11-07');
        $this->getJson('/local-api/reports/demand?from=2026-11-08&to=2026-11-07')->assertUnprocessable();
    }

    public function test_an_administrator_cannot_remove_their_own_access(): void
    {
        $admin = $this->staff();
        $this->actingAs($admin)->patchJson('/local-api/users/'.$admin->id, ['active' => 0])->assertUnprocessable();
        $this->assertTrue($admin->fresh()->active);
    }
}
