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

    public function test_purchase_detail_reads_only_its_lines_and_requires_inventory_access(): void
    {
        $this->actingAs($this->staff('stock'));
        $unit = DB::table('inventory_units')->where('symbol', 'kg')->value('id');
        $item = $this->postJson('/local-api/inventory/master/items', ['name' => 'Arroz detalle', 'unit_id' => $unit, 'minimum_milli' => 0])->assertOk()->json('id');
        $supplier = $this->postJson('/local-api/inventory/master/suppliers', ['name' => 'Proveedor detalle'])->assertOk()->json('id');
        $buy = fn ($quantity, $cost) => ['request_id' => (string) Str::uuid(), 'supplier_id' => $supplier,
            'reference' => 'Compra semanal', 'lines' => [['item_id' => $item, 'quantity_milli' => $quantity, 'cost_cents' => $cost]]];
        $id = $this->postJson('/local-api/inventory/purchases', $buy(20000, 5000))->assertOk()->json('id');
        $this->postJson('/local-api/inventory/purchases', $buy(10000, 2500))->assertOk();
        $url = '/local-api/inventory/purchases/'.$id;
        $this->getJson($url)->assertOk()->assertJsonPath('supplier', 'Proveedor detalle')
            ->assertJsonPath('reference', 'Compra semanal')->assertJsonPath('total_cents', 5000)
            ->assertJsonCount(1, 'lines')->assertJsonPath('lines.0.name', 'Arroz detalle')
            ->assertJsonPath('lines.0.quantity_milli', 20000)->assertJsonPath('lines.0.symbol', 'kg')
            ->assertJsonPath('lines.0.cost_cents', 5000)->assertJsonStructure(['created_at']);
        $this->assertDatabaseHas('inventory_items', ['id' => $item, 'stock_milli' => 30000]);
        $this->getJson('/local-api/inventory/purchases/99999999')->assertNotFound();
        $this->actingAs($this->staff('waiter'))->getJson($url)->assertForbidden();
    }

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'estacion_operaciones_test') {
            throw new \RuntimeException('Las pruebas solo pueden ejecutarse en estacion_operaciones_test.');
        }
    }

    public function test_configured_cash_turns_can_be_used_successively_without_opening_simultaneously(): void
    {
        $this->actingAs($this->staff());
        $view=$this->getJson('/local-api/cash')->assertOk()->assertJsonCount(3,'templates')->assertJsonPath('shift',null)->json();
        $morning=$view['templates'][0]['id'];$afternoon=$view['templates'][1]['id'];$night=$view['templates'][2]['id'];
        $this->postJson('/local-api/cash/templates',['name'=>'turno tarde','active'=>true])->assertUnprocessable();
        $first=$this->postJson('/local-api/cash/open',['template_id'=>$morning,'opening_cents'=>10000])->assertOk()->json('id');
        $this->postJson('/local-api/cash/open',['template_id'=>$afternoon,'opening_cents'=>5000])->assertConflict();
        $this->postJson('/local-api/cash/templates',['id'=>$morning,'name'=>'Mañana principal','active'=>false])->assertOk();
        $this->assertDatabaseHas('cash_shifts',['id'=>$first,'name'=>'Turno Mañana','closed_at'=>null]);
        $this->postJson('/local-api/cash/templates/'.$morning.'/delete')->assertUnprocessable();
        $this->postJson('/local-api/cash/close',['shift_id'=>$first,'counted_cents'=>10000])->assertOk();
        $this->postJson('/local-api/cash/open',['template_id'=>$morning,'opening_cents'=>0])->assertUnprocessable();
        $second=$this->postJson('/local-api/cash/open',['template_id'=>$afternoon,'opening_cents'=>10000])->assertOk()->json('id');
        $this->postJson('/local-api/cash/close',['shift_id'=>$second,'counted_cents'=>10000])->assertOk();
        $this->postJson('/local-api/cash/open',['template_id'=>$night,'opening_cents'=>10000])->assertOk();
        $this->assertDatabaseCount('cash_shifts',3);
        $this->assertSame(1,DB::table('cash_shifts')->whereNull('closed_at')->count());
        $this->getJson('/local-api/cash')->assertOk()->assertJsonPath('shift.name','Turno Noche')->assertJsonPath('shift.opening_cents',10000);
        $this->actingAs($this->staff('cashier'))->postJson('/local-api/cash/templates',['name'=>'No autorizado','active'=>true])->assertForbidden();
    }

    private function incidentItem(): int
    {
        $this->actingAs($this->staff('stock'));
        $id=$this->postJson('/local-api/inventory/master/items',['name'=>'Arroz incidencia','unit_id'=>DB::table('inventory_units')->first()->id,'minimum_milli'=>0])->assertOk()->json('id');
        $this->postJson('/local-api/inventory/movements',['request_id'=>(string)Str::uuid(),'type'=>'entrada','item_id'=>$id,'quantity_milli'=>10000,'cost_cents'=>5000,'reason'=>'Existencias iniciales'])->assertOk();
        return $id;
    }

    public function test_incident_loss_is_pending_until_admin_applies_once_and_can_be_reversed_once(): void
    {
        $item=$this->incidentItem();
        $body=['request_id'=>(string)Str::uuid(),'kind'=>'merma','item_id'=>$item,'quantity_milli'=>1000,'expected_stock_milli'=>10000,'expected_stock_value_cents'=>5000,'reason'=>'Derrame accidental'];
        $id=$this->postJson('/local-api/inventory/incidents',$body)->assertOk()->json('id');
        $this->postJson('/local-api/inventory/incidents',$body)->assertOk()->assertJsonPath('id',$id);
        $changed=$body;$changed['quantity_milli']=2000;$this->postJson('/local-api/inventory/incidents',$changed)->assertConflict();
        $this->assertDatabaseHas('inventory_items',['id'=>$item,'stock_milli'=>10000]);
        $url='/local-api/inventory/incidents/'.$id;
        $this->postJson($url.'/apply')->assertForbidden();
        $this->actingAs($this->staff())->postJson($url.'/apply')->assertOk();
        $this->postJson($url.'/apply')->assertConflict();
        $this->assertDatabaseHas('inventory_items',['id'=>$item,'stock_milli'=>9000,'stock_value_cents'=>4500]);
        $this->getJson('/local-api/inventory/incidents?kind=merma')->assertOk()->assertJsonPath('summary.loss_cents',500)->assertJsonPath('records.data.0.state','applied');
        $this->postJson($url.'/cancel',['reason'=>'Registro equivocado'])->assertOk();
        $this->postJson($url.'/cancel',['reason'=>'Repetición'])->assertConflict();
        $this->assertDatabaseHas('inventory_items',['id'=>$item,'stock_milli'=>10000,'stock_value_cents'=>5000]);
        $this->assertSame(1,DB::table('inventory_movements')->where('type','merma')->count());
        $this->assertSame(1,DB::table('inventory_movements')->where('type','reversa_merma')->count());
        $this->actingAs($this->staff('waiter'))->getJson('/local-api/inventory/incidents?kind=merma')->assertForbidden();
    }

    public function test_incident_count_blocks_stale_stock_and_reversal_after_subsequent_movements(): void
    {
        $item=$this->incidentItem();
        $body=['request_id'=>(string)Str::uuid(),'kind'=>'desvio','item_id'=>$item,'counted_milli'=>12000,'expected_stock_milli'=>10000,'expected_stock_value_cents'=>5000,'reason'=>'Conteo físico'];
        $id=$this->postJson('/local-api/inventory/incidents',$body)->assertOk()->json('id');
        $this->postJson('/local-api/inventory/movements',['request_id'=>(string)Str::uuid(),'type'=>'entrada','item_id'=>$item,'quantity_milli'=>1000,'cost_cents'=>500,'reason'=>'Compra posterior'])->assertOk();
        $this->actingAs($this->staff())->postJson('/local-api/inventory/incidents/'.$id.'/apply')->assertConflict();
        $this->postJson('/local-api/inventory/incidents/'.$id.'/cancel',['reason'=>'Rehacer conteo'])->assertOk();
        $body['request_id']=(string)Str::uuid();$body['expected_stock_milli']=11000;$body['expected_stock_value_cents']=5500;
        $id=$this->postJson('/local-api/inventory/incidents',$body)->assertOk()->json('id');
        $this->postJson('/local-api/inventory/incidents/'.$id.'/apply')->assertOk();
        $this->assertDatabaseHas('inventory_items',['id'=>$item,'stock_milli'=>12000,'stock_value_cents'=>6000]);
        $this->postJson('/local-api/inventory/movements',['request_id'=>(string)Str::uuid(),'type'=>'salida','item_id'=>$item,'quantity_milli'=>1000,'reason'=>'Uso posterior'])->assertOk();
        $this->postJson('/local-api/inventory/incidents/'.$id.'/cancel',['reason'=>'No debe revertir'])->assertConflict();
        $this->assertDatabaseHas('inventory_items',['id'=>$item,'stock_milli'=>11000,'stock_value_cents'=>5500]);
        $body['request_id']=(string)Str::uuid();$body['counted_milli']=11000;$this->postJson('/local-api/inventory/incidents',$body)->assertUnprocessable();
    }

    public function test_inventory_categories_crud_assignment_and_permissions(): void
    {
        $this->actingAs($this->staff('stock'));
        $id = $this->postJson('/local-api/inventory/categories', ['name' => '  Abarrotes  '])->assertOk()->json('id');
        $this->postJson('/local-api/inventory/categories', ['name' => 'abarrotes'])->assertUnprocessable();
        $unit = DB::table('inventory_units')->first()->id;
        $item = $this->postJson('/local-api/inventory/master/items', ['name' => 'Arroz categoría', 'unit_id' => $unit, 'category_id' => $id, 'minimum_milli' => 0])->assertOk()->json('id');
        $this->postJson('/local-api/inventory/categories', ['id' => $id, 'name' => 'Despensa'])->assertOk();
        $this->getJson('/local-api/inventory')->assertOk()->assertJsonPath('categories.0.name', 'Despensa')->assertJsonPath('categories.0.item_count', 1);
        $this->assertDatabaseHas('inventory_items', ['id' => $item, 'category_id' => $id, 'category' => 'Despensa', 'stock_milli' => 0]);
        $this->postJson('/local-api/inventory/categories/'.$id.'/delete')->assertUnprocessable();
        $this->postJson('/local-api/inventory/master/items', ['id' => $item, 'name' => 'Arroz categoría', 'unit_id' => $unit, 'category_id' => null, 'minimum_milli' => 0])->assertOk();
        $this->postJson('/local-api/inventory/categories/'.$id.'/delete')->assertOk();
        $this->assertDatabaseMissing('inventory_categories', ['id' => $id]);
        $this->postJson('/local-api/inventory/categories/'.$id.'/delete')->assertNotFound();
        $this->postJson('/local-api/inventory/master/items', ['id' => $item, 'name' => 'Arroz categoría', 'unit_id' => $unit, 'category_id' => 999999, 'minimum_milli' => 0])->assertUnprocessable();
        $this->actingAs($this->staff('waiter'))->postJson('/local-api/inventory/categories', ['name' => 'Sin permiso'])->assertForbidden();
        $this->assertDatabaseHas('operations_audit', ['action' => 'inventario.categoria.eliminada', 'entity_id' => (string) $id]);
    }

    public function test_purchase_cancellation_reverses_original_value_once_and_preserves_detail(): void
    {
        $actor = $this->staff('stock'); $this->actingAs($actor);
        $item = $this->postJson('/local-api/inventory/master/items', ['name' => 'Arroz anulación', 'unit_id' => DB::table('inventory_units')->first()->id, 'minimum_milli' => 0])->json('id');
        $supplier = $this->postJson('/local-api/inventory/master/suppliers', ['name' => 'Proveedor anulación'])->json('id');
        $buy = fn ($cost) => $this->postJson('/local-api/inventory/purchases', ['request_id' => (string) Str::uuid(), 'supplier_id' => $supplier,
            'lines' => [['item_id' => $item, 'quantity_milli' => 10000, 'cost_cents' => $cost]]])->assertOk()->json('id');
        $first = $buy(3000); $buy(5000);
        $url = '/local-api/inventory/purchases/'.$first.'/cancel';
        $this->postJson($url, ['reason' => '   '])->assertUnprocessable();
        $this->postJson($url, ['reason' => 'Compra duplicada'])->assertOk();
        $this->assertDatabaseHas('inventory_items', ['id' => $item, 'stock_milli' => 10000, 'stock_value_cents' => 5000]);
        $this->assertDatabaseHas('inventory_purchases', ['id' => $first, 'cancelled_by' => $actor->id, 'cancellation_reason' => 'Compra duplicada']);
        $this->postJson($url, ['reason' => 'Otra vez'])->assertConflict();
        $this->assertSame(1, DB::table('inventory_movements')->where('type', 'anulacion_compra')->count());
        $this->getJson('/local-api/inventory/purchases/'.$first)->assertOk()->assertJsonCount(1, 'lines')
            ->assertJsonPath('lines.0.cost_cents', 3000)->assertJsonPath('cancellation_reason', 'Compra duplicada')->assertJsonPath('cancelled_by_name', $actor->name);
        $this->assertDatabaseHas('operations_audit', ['action' => 'inventario.compra.anulada', 'entity_id' => (string) $first]);
        $this->actingAs($this->staff('waiter'))->postJson($url, ['reason' => 'Sin permisos'])->assertForbidden();
    }

    public function test_purchase_cancellation_rolls_back_every_line_when_an_item_has_been_used_even_if_replenished(): void
    {
        $this->actingAs($this->staff('stock'));
        $unit = DB::table('inventory_units')->first()->id;
        $ids = [];
        foreach (['Insumo A', 'Insumo B'] as $name) $ids[] = $this->postJson('/local-api/inventory/master/items', ['name' => $name, 'unit_id' => $unit, 'minimum_milli' => 0])->json('id');
        $supplier = $this->postJson('/local-api/inventory/master/suppliers', ['name' => 'Proveedor protegido'])->json('id');
        $id = $this->postJson('/local-api/inventory/purchases', ['request_id' => (string) Str::uuid(), 'supplier_id' => $supplier,
            'lines' => array_map(fn ($item) => ['item_id' => $item, 'quantity_milli' => 10000, 'cost_cents' => 5000], $ids)])->assertOk()->json('id');
        foreach (['salida', 'entrada'] as $type) $this->postJson('/local-api/inventory/movements', ['request_id' => (string) Str::uuid(),
            'type' => $type, 'item_id' => $ids[1], 'quantity_milli' => 1000, 'cost_cents' => 500, 'reason' => 'Movimiento posterior'])->assertOk();
        $this->postJson('/local-api/inventory/purchases/'.$id.'/cancel', ['reason' => 'Intento de anulación'])->assertUnprocessable();
        foreach ($ids as $item) $this->assertDatabaseHas('inventory_items', ['id' => $item, 'stock_milli' => 10000, 'stock_value_cents' => 5000]);
        $this->assertDatabaseHas('inventory_purchases', ['id' => $id, 'cancelled_at' => null]);
        $this->assertSame(0, DB::table('inventory_movements')->where('type', 'anulacion_compra')->count());
    }

    public function test_inventory_unit_deletion_preserves_referenced_units_and_checks_permissions(): void
    {
        $this->actingAs($this->staff('stock'));
        $id = $this->postJson('/local-api/inventory/master/units', ['name' => 'Bolsa', 'symbol' => 'bl'])->assertOk()->json('id');
        $url = '/local-api/inventory/units/'.$id.'/delete';
        $this->actingAs($this->staff('waiter'))->postJson($url)->assertForbidden();
        $this->assertDatabaseHas('inventory_units', ['id' => $id]);
        $this->actingAs($this->staff('stock'))->postJson($url)->assertOk()->assertJsonPath('deleted', true);
        $this->assertDatabaseMissing('inventory_units', ['id' => $id]);
        $this->assertDatabaseHas('operations_audit', ['action' => 'inventario.unidad.eliminada', 'entity_id' => (string) $id]);
        $this->postJson($url)->assertNotFound();
        $kg = DB::table('inventory_units')->where('symbol', 'kg')->value('id');
        $this->postJson('/local-api/inventory/master/items', ['name' => 'Arroz protegido', 'unit_id' => $kg, 'minimum_milli' => 0])->assertOk();
        $this->postJson('/local-api/inventory/units/'.$kg.'/delete')->assertUnprocessable();
        $this->assertDatabaseHas('inventory_units', ['id' => $kg]);
        $this->assertDatabaseHas('inventory_items', ['name' => 'Arroz protegido', 'unit_id' => $kg]);
    }

    public function test_record_removal_checks_inventory_dependencies_and_preserves_stock_history(): void
    {
        $this->actingAs($this->staff('stock'));
        $item = $this->postJson('/local-api/inventory/master/items', ['name' => 'Temporal', 'unit_id' => DB::table('inventory_units')->first()->id, 'minimum_milli' => 0])->json('id');
        $this->postJson('/local-api/inventory/remove/items/'.$item)->assertOk();
        $this->assertDatabaseMissing('inventory_items', ['id' => $item]);
        $supplier = $this->postJson('/local-api/inventory/master/suppliers', ['name' => 'Temporal proveedor'])->json('id');
        $this->postJson('/local-api/inventory/remove/suppliers/'.$supplier)->assertOk();
        [$recipe, $item] = $this->dailyRecipe();
        config(['restaurant.daily_availability' => false]);
        $batch = $this->postJson('/local-api/inventory/batches', ['request_id' => (string) Str::uuid(), 'recipe_id' => $recipe, 'portions' => 2])->assertOk()->json('id');
        foreach (['items' => $item, 'recipes' => $recipe, 'batches' => $batch] as $kind => $id) {
            $this->postJson('/local-api/inventory/remove/'.$kind.'/'.$id)->assertUnprocessable();
        }
        $this->assertDatabaseHas('inventory_items', ['id' => $item, 'stock_milli' => 9500]);
        $this->assertDatabaseHas('inventory_batches', ['id' => $batch, 'remaining' => 2]);
        $this->actingAs($this->staff('waiter'))->postJson('/local-api/inventory/remove/items/'.$item)->assertForbidden();
        $this->postJson('/local-api/operations-settings/remove/centers/kitchen')->assertForbidden();
    }

    public function test_settings_removal_clears_assignments_but_protects_used_configuration_and_paid_orders(): void
    {
        $this->actingAs($this->staff());
        $this->postJson('/local-api/operations-settings/centers', ['code' => 'test-center', 'name' => 'Temporal', 'active' => true])->assertUnprocessable();
        $this->assertDatabaseMissing('production_centers', ['code' => 'test-center']);
        $this->postJson('/local-api/operations-settings/remove/centers/kitchen')->assertUnprocessable();
        $this->postJson('/local-api/operations-settings/remove/categories/marinos')->assertUnprocessable();
        $this->postJson('/local-api/operations-settings/remove/routes/bebidas')->assertOk();
        $this->assertDatabaseMissing('production_routes', ['category' => 'bebidas']);
        $this->postJson('/local-api/operations-settings/printers', ['code' => 'kitchen', 'printer' => 'Prueba'])->assertOk();
        $this->postJson('/local-api/operations-settings/remove/printers/kitchen')->assertOk();
        $this->assertDatabaseHas('production_centers', ['code' => 'kitchen', 'printer' => null]);
        $method = ['code' => 'test-pay', 'name' => 'Temporal', 'cash' => false, 'active' => true];
        $this->postJson('/local-api/operations-settings/methods', $method)->assertOk();
        $this->postJson('/local-api/operations-settings/remove/methods/test-pay')->assertOk();
        $this->postJson('/local-api/operations-settings/methods', $method)->assertOk();
        $id = $this->postJson('/local-api/orders', $this->payload())->assertCreated()->json('order.id');
        $this->postJson('/local-api/cash/open', ['name' => 'Prueba', 'opening_cents' => 0])->assertOk();
        $this->postJson('/local-api/cash/orders/'.$id.'/payment', ['method' => 'test-pay', 'expected_total_cents' => 4700])->assertOk();
        $this->postJson('/local-api/operations-settings/remove/methods/test-pay')->assertUnprocessable();
        $this->postJson('/local-api/inventory/remove/orders/'.$id, ['expectedStatus' => 'pending'])->assertConflict();
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'pending']);
        $this->assertDatabaseHas('order_payments', ['order_id' => $id, 'amount_cents' => 4700]);
        $other = $this->postJson('/local-api/orders', $this->payload())->assertCreated()->json('order.id');
        $this->postJson('/local-api/inventory/remove/orders/'.$other, ['expectedStatus' => 'pending'])->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $other, 'status' => 'cancelled']);
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

    private function dailyRecipe(int $direct = 3): array
    {
        config(['restaurant.daily_availability' => true]);
        $this->actingAs($this->staff());
        $item = $this->postJson('/local-api/inventory/master/items', ['name' => 'Arroz diario',
            'unit_id' => DB::table('inventory_units')->where('symbol', 'kg')->value('id'), 'minimum_milli' => 0])->assertOk()->json('id');
        $this->postJson('/local-api/inventory/movements', ['request_id' => (string) Str::uuid(), 'type' => 'entrada',
            'item_id' => $item, 'quantity_milli' => 10000, 'cost_cents' => 10000, 'reason' => 'Prueba de producción'])->assertOk();
        $this->postJson('/local-api/inventory/recipes', ['product_key' => 'carta:arroz', 'mode' => 'direct', 'batch_yield' => 10,
            'components' => [['item_id' => $item, 'quantity_milli' => 250]]])->assertOk();
        $recipe = DB::table('inventory_recipes')->where('product_key', 'carta:arroz')->value('id');
        $this->postJson('/local-api/inventory/plans', ['recipe_id' => $recipe, 'day' => '2026-11-07',
            'direct_portions' => $direct, 'batch_count' => 2])->assertOk();
        return [$recipe, $item];
    }

    public function test_paused_daily_availability_does_not_block_orders_or_automatically_consume_stock(): void
    {
        [, $item] = $this->dailyRecipe(3);
        $previous = $this->postJson('/local-api/orders', $this->payload())->assertCreated()->json('order.id');
        config(['restaurant.daily_availability' => false]);
        $id = $this->postJson('/local-api/orders', $this->payload())->assertCreated()->json('order.id');
        $this->assertDatabaseCount('inventory_portion_reservations', 1);
        $this->assertDatabaseMissing('inventory_portion_reservations', ['order_id' => $id]);
        $this->patchJson('/local-api/orders/'.$previous.'/tickets/kitchen', ['expectedStatus' => 'pending', 'expectedRevision' => 0])->assertOk();
        $this->assertDatabaseHas('inventory_portion_reservations', ['order_id' => $previous, 'state' => 'released']);
        $this->patchJson('/local-api/orders/'.$id.'/tickets/kitchen', ['expectedStatus' => 'pending', 'expectedRevision' => 0])->assertOk();
        $this->assertDatabaseHas('inventory_items', ['id' => $item, 'stock_milli' => 10000]);
        $this->getJson('/local-api/inventory/availability?day=2026-11-07')->assertNotFound();
        $this->postJson('/local-api/inventory/plans', [])->assertNotFound();
        $this->getJson('/local-api/inventory')->assertOk()->assertJsonCount(0, 'dailyPlans');
    }

    public function test_batch_ingredient_history_survives_recipe_edits_and_direct_recipes_do_not_consume_ingredients_twice(): void
    {
        [$recipe, $item] = $this->dailyRecipe();
        config(['restaurant.daily_availability' => false]);
        $batch = $this->postJson('/local-api/inventory/batches', ['request_id' => (string) Str::uuid(), 'recipe_id' => $recipe, 'portions' => 5])->assertOk()->json('id');
        $this->assertDatabaseHas('inventory_items', ['id' => $item, 'stock_milli' => 8750]);
        $this->postJson('/local-api/inventory/recipes', ['product_key' => 'carta:arroz', 'mode' => 'direct', 'batch_yield' => 10,
            'components' => [['item_id' => $item, 'quantity_milli' => 1000]]])->assertOk();
        $url = '/local-api/inventory/batches/'.$batch;
        $this->getJson($url)->assertOk()->assertJsonPath('portions', 5)->assertJsonCount(1, 'lines')
            ->assertJsonPath('lines.0.quantity_milli', 1250)->assertJsonPath('lines.0.cost_cents', 1250)
            ->assertJsonPath('lines.0.symbol', 'kg');
        $id = $this->postJson('/local-api/orders', $this->payload())->assertCreated()->json('order.id');
        foreach (DB::table('production_tickets')->where('order_id', $id)->pluck('center') as $center) {
            $this->patchJson('/local-api/orders/'.$id.'/tickets/'.$center, ['expectedStatus' => 'pending', 'expectedRevision' => 0])->assertOk();
            $this->patchJson('/local-api/orders/'.$id.'/tickets/'.$center, ['expectedStatus' => 'preparing', 'expectedRevision' => 0])->assertOk();
        }
        $this->postJson('/local-api/inventory/orders/'.$id.'/consume')->assertOk()->assertJsonPath('consumption.cost_cents', 500);
        $this->postJson('/local-api/inventory/orders/'.$id.'/consume')->assertOk();
        $this->assertDatabaseHas('inventory_items', ['id' => $item, 'stock_milli' => 8750]);
        $this->assertDatabaseHas('inventory_batches', ['id' => $batch, 'remaining' => 3]);
        $movementCount = DB::table('inventory_movements')->count();
        $this->getJson($url)->assertOk()->assertJsonPath('lines.0.quantity_milli', 1250)->assertJsonPath('remaining', 3);
        $this->assertDatabaseCount('inventory_movements', $movementCount);
        $this->getJson('/local-api/inventory/batches/99999999')->assertNotFound();
        $this->actingAs($this->staff('waiter'))->getJson($url)->assertForbidden();
    }

    public function test_daily_plan_reserves_on_confirmation_releases_cancellation_and_rolls_back_failed_edits(): void
    {
        [, $item] = $this->dailyRecipe();
        $payload = $this->payload();
        $id = $this->postJson('/local-api/orders', $payload)->assertCreated()->json('order.id');
        $this->postJson('/local-api/orders', $payload)->assertOk();
        $this->assertDatabaseCount('inventory_portion_reservations', 1);
        $this->getJson('/local-api/inventory?day=2026-11-07')->assertOk()->assertJsonPath('dailyPlans.0.available', 1)
            ->assertJsonPath('dailyPlans.0.reserved', 2);
        $this->assertDatabaseHas('inventory_items', ['id' => $item, 'stock_milli' => 10000]);
        $edit = $payload;
        unset($edit['requestId']);
        $edit['expectedRevision'] = 0;
        $edit['items'][0]['quantity'] = 4;
        $edit['expectedTotalCents'] = 8900;
        $this->patchJson('/local-api/staff-orders/'.$id, $edit)->assertUnprocessable();
        $this->assertDatabaseHas('orders', ['id' => $id, 'revision' => 0, 'total_cents' => 4700]);
        $this->assertDatabaseHas('inventory_portion_reservations', ['order_id' => $id, 'state' => 'reserved', 'portions' => 2]);
        $this->postJson('/local-api/orders', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('orders', 1);
        $this->patchJson('/local-api/orders/'.$id, ['status' => 'cancelled', 'expectedStatus' => 'pending', 'reason' => 'Cliente cancela'])->assertOk();
        $this->getJson('/local-api/inventory?day=2026-11-07')->assertOk()->assertJsonPath('dailyPlans.0.available', 3)
            ->assertJsonPath('dailyPlans.0.reserved', 0);
    }

    public function test_daily_batch_production_is_costed_once_and_reserved_batches_cannot_be_wasted(): void
    {
        [$recipe, $item] = $this->dailyRecipe(2);
        $production = ['request_id' => (string) Str::uuid(), 'recipe_id' => $recipe, 'batch_count' => 2, 'portions' => 20];
        $batch = $this->postJson('/local-api/inventory/batches', $production)->assertOk()->json('id');
        $this->postJson('/local-api/inventory/batches', $production)->assertOk();
        $this->assertDatabaseCount('inventory_batches', 1);
        $this->assertDatabaseHas('inventory_items', ['id' => $item, 'stock_milli' => 5000]);
        $id = $this->postJson('/local-api/orders', $this->payload())->assertCreated()->json('order.id');
        $this->getJson('/local-api/inventory?day=2026-11-07')->assertOk()->assertJsonPath('dailyPlans.0.available', 20)
            ->assertJsonPath('dailyPlans.0.produced_portions', 20);
        $this->postJson('/local-api/inventory/movements', ['request_id' => (string) Str::uuid(), 'type' => 'merma',
            'batch_id' => $batch, 'quantity_milli' => 19000, 'reason' => 'No debe quitar porciones reservadas'])->assertUnprocessable();
        $this->patchJson('/local-api/orders/'.$id.'/tickets/kitchen', ['expectedStatus' => 'pending', 'expectedRevision' => 0])->assertOk();
        $this->assertDatabaseHas('inventory_batches', ['id' => $batch, 'remaining' => 18]);
        $this->patchJson('/local-api/orders/'.$id.'/tickets/kitchen', ['expectedStatus' => 'preparing', 'expectedRevision' => 0])->assertOk();
        // A second area may still be pending; finish it independently.
        foreach (DB::table('production_tickets')->where('order_id', $id)->where('status', 'pending')->pluck('center') as $center) {
            $this->patchJson('/local-api/orders/'.$id.'/tickets/'.$center, ['expectedStatus' => 'pending', 'expectedRevision' => 0])->assertOk();
            $this->patchJson('/local-api/orders/'.$id.'/tickets/'.$center, ['expectedStatus' => 'preparing', 'expectedRevision' => 0])->assertOk();
        }
        $this->postJson('/local-api/inventory/orders/'.$id.'/consume')->assertOk()->assertJsonPath('consumption.cost_cents', 500);
        $this->postJson('/local-api/inventory/orders/'.$id.'/consume')->assertOk();
        $this->assertDatabaseHas('inventory_items', ['id' => $item, 'stock_milli' => 5000]);
        $this->assertDatabaseHas('inventory_batches', ['id' => $batch, 'remaining' => 18]);
        $this->patchJson('/local-api/orders/'.$id, ['status' => 'cancelled', 'expectedStatus' => 'ready', 'reason' => 'No retirar'])->assertOk();
        $this->getJson('/local-api/inventory?day=2026-11-07')->assertOk()->assertJsonPath('dailyPlans.0.available', 20)
            ->assertJsonPath('dailyPlans.0.wasted', 2)->assertJsonPath('dailyPlans.0.reserved', 0);
    }

    public function test_daily_direct_recipe_uses_confirmation_snapshot_and_cannot_reduce_committed_capacity(): void
    {
        [$recipe, $item] = $this->dailyRecipe();
        $id = $this->postJson('/local-api/orders', $this->payload())->assertCreated()->json('order.id');
        $this->postJson('/local-api/inventory/plans', ['recipe_id' => $recipe, 'day' => '2026-11-07',
            'direct_portions' => 1, 'batch_count' => 0, 'expected_revision' => 0])->assertUnprocessable();
        $this->postJson('/local-api/inventory/plans', ['recipe_id' => $recipe, 'day' => '2026-11-07',
            'direct_portions' => 4, 'batch_count' => 0, 'expected_revision' => 0])->assertOk();
        $this->postJson('/local-api/inventory/plans', ['recipe_id' => $recipe, 'day' => '2026-11-07',
            'direct_portions' => 5, 'batch_count' => 0, 'expected_revision' => 0])->assertConflict();
        $this->postJson('/local-api/inventory/recipes', ['product_key' => 'carta:arroz', 'mode' => 'direct', 'batch_yield' => 10,
            'components' => [['item_id' => $item, 'quantity_milli' => 1000]]])->assertOk();
        $this->patchJson('/local-api/orders/'.$id.'/tickets/kitchen', ['expectedStatus' => 'pending', 'expectedRevision' => 0])->assertOk();
        $this->assertDatabaseHas('inventory_items', ['id' => $item, 'stock_milli' => 9500]);
        $this->patchJson('/local-api/orders/'.$id.'/tickets/kitchen', ['expectedStatus' => 'pending', 'expectedRevision' => 0])->assertConflict();
        $this->patchJson('/local-api/orders/'.$id, ['status' => 'cancelled', 'expectedStatus' => 'preparing', 'reason' => 'Cancelado después de preparar'])->assertOk();
        $this->assertDatabaseHas('inventory_items', ['id' => $item, 'stock_milli' => 9500]);
        $this->getJson('/local-api/inventory?day=2026-11-07')->assertOk()->assertJsonPath('dailyPlans.0.available', 2)
            ->assertJsonPath('dailyPlans.0.wasted', 2);
        $this->actingAs($this->staff('waiter'))->postJson('/local-api/inventory/plans', [])->assertForbidden();
    }

    public function test_daily_mixed_production_uses_batches_first_and_then_direct_portions(): void
    {
        [$recipe, $item] = $this->dailyRecipe(3);
        $this->postJson('/local-api/inventory/batches', ['request_id' => (string) Str::uuid(), 'recipe_id' => $recipe, 'portions' => 5])->assertOk();
        $payload = $this->payload();
        $payload['items'][0]['quantity'] = 7;
        $payload['expectedTotalCents'] = 15200;
        $id = $this->postJson('/local-api/orders', $payload)->assertCreated()->json('order.id');
        $this->assertDatabaseHas('inventory_portion_reservations', ['order_id' => $id, 'batch_id' => null, 'portions' => 2]);
        $this->getJson('/local-api/inventory/availability?day=2026-11-07')->assertOk()->assertJsonPath('dailyPlans.0.available', 1);
        $this->patchJson('/local-api/orders/'.$id.'/tickets/kitchen', ['expectedStatus' => 'pending', 'expectedRevision' => 0])->assertOk();
        $this->assertDatabaseHas('inventory_items', ['id' => $item, 'stock_milli' => 8250]);
        $this->patchJson('/local-api/orders/'.$id, ['status' => 'cancelled', 'expectedStatus' => 'preparing', 'reason' => 'Prueba'])->assertOk();
        $this->getJson('/local-api/inventory/availability?day=2026-11-07')->assertOk()->assertJsonPath('dailyPlans.0.available', 1)
            ->assertJsonPath('dailyPlans.0.wasted', 7);
    }

    public function test_daily_control_requires_a_new_plan_on_the_next_lima_day_and_does_not_sell_old_batches(): void
    {
        [$recipe, $item] = $this->dailyRecipe(0);
        $this->postJson('/local-api/inventory/batches', ['request_id' => (string) Str::uuid(), 'recipe_id' => $recipe, 'portions' => 5])->assertOk();
        Carbon::setTestNow('2026-11-08 02:00:00 UTC');
        CarbonImmutable::setTestNow('2026-11-08 02:00:00 UTC');
        $this->getJson('/local-api/inventory')->assertOk()->assertJsonPath('productionDay', '2026-11-07')->assertJsonPath('dailyPlans.0.available', 5);
        Carbon::setTestNow('2026-11-08 05:00:00 UTC');
        CarbonImmutable::setTestNow('2026-11-08 05:00:00 UTC');
        $this->postJson('/local-api/orders', $this->payload())->assertUnprocessable();
        $this->postJson('/local-api/inventory/plans', ['recipe_id' => $recipe, 'day' => '2026-11-08', 'direct_portions' => 0, 'batch_count' => 2])->assertOk();
        $this->getJson('/local-api/inventory')->assertOk()->assertJsonPath('dailyPlans.0.available', 0)->assertJsonPath('dailyPlans.0.produced_portions', 0);
        $this->postJson('/local-api/orders', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('inventory_items', ['id' => $item, 'stock_milli' => 8750]);
    }

    public function test_daily_successful_edit_replaces_reservations_without_holding_the_old_quantity(): void
    {
        $this->dailyRecipe(3);
        $payload = $this->payload();
        $id = $this->postJson('/local-api/staff-orders', $payload)->assertCreated()->json('order.id');
        unset($payload['requestId']);
        $payload['items'][0]['quantity'] = 1;
        $payload['expectedTotalCents'] = 2600;
        $payload['expectedRevision'] = 0;
        $this->patchJson('/local-api/staff-orders/'.$id, $payload)->assertOk();
        $this->getJson('/local-api/inventory/availability?day=2026-11-07')->assertOk()->assertJsonPath('dailyPlans.0.available', 2)
            ->assertJsonPath('dailyPlans.0.reserved', 1);
        $this->postJson('/local-api/orders', $this->payload())->assertCreated();
        $this->getJson('/local-api/inventory/availability?day=2026-11-07')->assertOk()->assertJsonPath('dailyPlans.0.available', 0);
        $this->postJson('/local-api/orders', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('orders', 2);
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

    public function test_kitchen_unification_preserves_items_states_and_original_ticket_audit(): void
    {
        DB::table('production_centers')->insert(['code' => 'bar', 'name' => 'Barra', 'active' => true]);
        DB::table('production_routes')->updateOrInsert(['category' => 'bebidas'], ['center' => 'bar']);
        DB::table('production_centers')->where('code', 'kitchen')->update(['printer' => 'Impresora cocina']);
        $ids = [];
        foreach (['pending', 'preparing', 'ready', 'delivered', 'cancelled'] as $status) {
            $id = $this->postJson('/local-api/orders', $this->payload())->assertCreated()->json('order.id');
            $order = Order::findOrFail($id);
            $ids[$status] = $id;
            $items = $order->items;
            DB::table('orders')->where('id', $id)->update(['status' => $status]);
            DB::table('production_tickets')->where('order_id', $id)->update([
                'items' => json_encode([$items[0]]), 'status' => $status === 'pending' ? 'pending' : 'ready',
            ]);
            DB::table('production_tickets')->insert(['order_id' => $id, 'center' => 'bar', 'items' => json_encode([$items[1]]), 'status' => 'pending']);
        }
        $migration = require database_path('migrations/2026_11_25_000001_unify_kitchen_preparation.php');
        $migration->up();
        $this->assertDatabaseCount('production_centers', 1);
        $this->assertDatabaseHas('production_centers', ['code' => 'kitchen', 'printer' => 'Impresora cocina']);
        $this->assertDatabaseHas('production_routes', ['category' => 'bebidas', 'center' => 'kitchen']);
        foreach ($ids as $status => $id) {
            $ticket = DB::table('production_tickets')->where('order_id', $id)->sole();
            $this->assertSame('kitchen', $ticket->center);
            $this->assertSame($status === 'delivered' ? 'ready' : $status, $ticket->status);
            $this->assertEquals(Order::find($id)->items, json_decode($ticket->items, true));
            $this->assertDatabaseHas('orders', ['id' => $id, 'status' => $status, 'revision' => 1]);
            $audit = DB::table('operations_audit')->where('action', 'comanda.cocina_unificada')->where('entity_id', (string) $id)->sole();
            $this->assertCount(2, json_decode($audit->details, true)['tickets']);
        }
        $migration->up();
        $this->assertDatabaseCount('production_tickets', 5);
        $this->actingAs($this->staff('kitchen'));
        $this->advanceTicket($ids['pending'], 'kitchen', 'pending', 0)->assertConflict();
        $this->advanceTicket($ids['pending'], 'kitchen', 'pending', 1)->assertOk();
        $this->advanceTicket($ids['pending'], 'bar', 'pending', 1)->assertUnprocessable();
        $this->actingAs($this->staff())->postJson('/local-api/operations-settings/centers', [
            'code' => 'bar', 'name' => 'Barra', 'active' => true,
        ])->assertUnprocessable();
        $this->postJson('/local-api/operations-settings/routes', ['category' => 'bebidas', 'center' => 'bar'])->assertUnprocessable();
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
        $this->patchJson('/local-api/orders/'.$id, ['status' => 'preparing', 'expectedStatus' => 'ready'])->assertConflict();
        $this->advanceTicket($id, 'kitchen', 'pending', 1)->assertConflict();
        $this->advanceTicket($id, 'kitchen', 'pending')->assertOk();
        $this->advanceTicket($id, 'kitchen', 'pending')->assertConflict();
        $this->advanceTicket($id, 'kitchen', 'preparing')->assertOk()->assertJsonPath('order.status', 'ready');
        $this->patchJson('/local-api/orders/'.$id, ['status' => 'delivered', 'expectedStatus' => 'ready'])->assertOk();
        $this->patchJson('/local-api/orders/'.$id, ['status' => 'cancelled', 'expectedStatus' => 'delivered', 'reason' => 'Solicitud del cliente'])->assertUnprocessable();
        $this->getJson('/local-api/orders')->assertJsonCount(0, 'orders');
        $this->getJson('/local-api/orders?scope=history&date=2026-11-07&search=Cliente')->assertOk()->assertJsonCount(1, 'orders');
        $this->getJson('/local-api/orders/'.$id)->assertOk()->assertJsonCount(4, 'order.events');
        DB::table('menu_products')->update(['price_cents' => 9999]);
        $this->assertEquals(4700, Order::find($id)->total_cents);
    }

    public function test_admin_can_assign_mesero_access_to_take_table_orders_and_read_the_customer_menu(): void
    {
        $this->actingAs($this->staff());
        $catalog = $this->getJson('/local-api/catalog')->assertOk()->json();
        $id = $this->postJson('/local-api/users', ['name' => 'Mesero de prueba', 'username' => 'mesero-prueba',
            'password' => 'Prueba-mesero-365!', 'role' => 'waiter'])->assertOk()->assertJsonPath('user.role', 'waiter')->json('user.id');
        $this->actingAs(User::findOrFail($id));
        $this->assertEquals($catalog, $this->getJson('/local-api/catalog')->assertOk()->json());
        $layout = $this->getJson('/local-api/salon-layout')->assertOk()->json();
        $table = $layout['zones'][0]['tables'][0];
        $payload = $this->payload();
        $payload['customer'] = ['method' => 'table', 'tableId' => $table['id'], 'tableLabel' => $table['label']];
        $payload['expectedTotalCents'] = 4500;
        $order = $this->postJson('/local-api/staff-orders', $payload)->assertCreated()
            ->assertJsonPath('order.tableLabel', $table['label'])->assertJsonPath('order.method', 'table')->json('order.id');
        $this->getJson('/local-api/orders/'.$order)->assertOk()->assertJsonCount(2, 'order.items');
        $this->assertDatabaseHas('order_events', ['order_id' => $order, 'user_id' => $id, 'status' => 'pending']);
        foreach (['/cash', '/inventory', '/operations-settings', '/users'] as $path) {
            $this->getJson('/local-api'.$path)->assertForbidden();
        }
        $this->putJson('/local-api/salon-layout', $layout)->assertForbidden();
        $this->patchJson('/local-api/orders/'.$order, ['status' => 'preparing', 'expectedStatus' => 'pending'])->assertForbidden();
        $this->patchJson('/local-api/orders/'.$order.'/tickets/kitchen', ['expectedStatus' => 'pending', 'expectedRevision' => 0])->assertForbidden();
        foreach (['pickup' => 4700, 'delivery' => 4700] as $method => $expected) {
            $outside = $this->payload();
            $outside['requestId'] = (string) Str::uuid();
            $outside['customer']['method'] = $method;
            if ($method === 'delivery') $outside['customer']['address'] = 'Calle de prueba 123';
            $outside['expectedTotalCents'] = $expected;
            $this->postJson('/local-api/staff-orders', $outside)->assertCreated()->assertJsonPath('order.method', $method);
        }
        $this->getJson('/local-api/orders')->assertOk()->assertJsonCount(3, 'orders');
        $this->actingAs($this->staff('kitchen'));
        $this->advanceTicket($order, 'kitchen', 'pending')->assertOk();
        $this->advanceTicket($order, 'kitchen', 'preparing')->assertOk();
        $this->actingAs(User::findOrFail($id))->patchJson('/local-api/orders/'.$order, ['status' => 'delivered', 'expectedStatus' => 'ready'])->assertOk();

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
        $this->advanceTicket($order['id'], 'kitchen', 'pending')->assertConflict();
        $this->advanceTicket($order['id'], 'kitchen', 'pending', 1)->assertOk();
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
                $this->advanceTicket($order['id'], 'kitchen', 'pending')->assertOk();
            }
            if ($status === 'ready') {
                $this->advanceTicket($order['id'], 'kitchen', 'preparing')->assertOk();
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

    public function test_cash_records_delivery_and_payment_independently_and_closes_the_table(): void
    {
        $payload = $this->payload();
        $payload['customer'] = ['method' => 'table', 'tableLabel' => 'Mesa 1'];
        $payload['expectedTotalCents'] = 4500;
        $order = $this->actingAs($this->staff('waiter'))->postJson('/local-api/staff-orders', $payload)->assertCreated()->json('order.id');
        $this->getJson('/local-api/cash')->assertForbidden();
        $this->advanceTicket($order, 'kitchen', 'pending')->assertForbidden();
        $this->putJson('/local-api/salon-layout', [])->assertForbidden();
        $this->actingAs($this->staff('cashier'));
        $this->getJson('/local-api/orders')->assertForbidden();
        $shift = $this->postJson('/local-api/cash/open', ['name' => 'Mañana', 'opening_cents' => 10000])->assertOk()->json('id');
        $this->postJson('/local-api/cash/open', ['name' => 'Otro', 'opening_cents' => 0])->assertConflict();
        $table = '/local-api/cash/orders/'.$order.'/close-table';
        $payment = '/local-api/cash/orders/'.$order.'/payment';
        $this->postJson($payment, ['method' => 'cash', 'expected_total_cents' => 4000])->assertConflict();
        $this->postJson($payment, ['method' => 'cash', 'expected_total_cents' => 4500])->assertOk();
        $this->postJson($payment, ['method' => 'cash', 'expected_total_cents' => 4500])->assertOk();
        $this->assertDatabaseCount('order_payments', 1);
        $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'pending']);
        $this->postJson($table)->assertConflict();
        $other = $this->postJson('/local-api/orders', $this->payload())->assertCreated()->json('order.id');
        $this->postJson('/local-api/cash/orders/'.$other.'/payment', ['method' => 'yape', 'expected_total_cents' => 4700])->assertOk();
        $expense = ['request_id' => (string) Str::uuid(), 'description' => 'Limpieza', 'amount_cents' => 500];
        $this->postJson('/local-api/cash/expenses', $expense)->assertNotFound();
        $this->assertDatabaseCount('cash_expenses', 0);
        // Previously recorded cash outflows still participate in reconciliation.
        DB::table('cash_expenses')->insert([...$expense, 'shift_id' => $shift,
            'user_id' => auth()->id(), 'created_at' => now()]);
        $this->getJson('/local-api/cash')->assertOk()
            ->assertJsonPath('shift.income_cents', 9200)->assertJsonPath('shift.expected_cents', 14000);

        $this->actingAs($this->staff('kitchen'));
        foreach (['kitchen'] as $center) {
            $this->advanceTicket($order, $center, 'pending')->assertOk();
            $this->advanceTicket($order, $center, 'preparing')->assertOk();
        }
        $this->patchJson('/local-api/orders/'.$order, ['status' => 'delivered', 'expectedStatus' => 'ready'])->assertOk();
        $this->getJson('/local-api/orders')->assertJsonFragment(['id' => $order, 'tableClosedAt' => null]);
        $this->actingAs($this->staff('cashier'))->postJson($table)->assertOk()->assertJsonPath('closed', 1);
        $this->postJson('/local-api/cash/close', ['shift_id' => $shift, 'counted_cents' => 13900])
            ->assertOk()->assertJsonPath('difference_cents', -100);
        $this->getJson('/local-api/cash')->assertJsonPath('history.0.expected_cents', 14000)->assertJsonPath('shift', null);
        $this->actingAs($this->staff())->getJson('/local-api/reports/operations?from=2026-11-07&to=2026-11-07')
            ->assertOk()->assertJsonPath('sales.0.amount_cents', 9200)->assertJsonPath('expenseTotal', 500);
        $this->assertDatabaseHas('operations_audit', ['action' => 'caja.mesa_cerrada', 'entity_id' => (string) $order]);
        // A paid and closed table remains consultable after its shift has closed.
        $history = $this->getJson('/local-api/cash/payments')->assertOk()
            ->assertJsonPath('records.total', 2)->assertJsonPath('total_cents', 9200)->json();
        $record = collect($history['records']['data'])->firstWhere('order_id', $order);
        $this->assertNotNull($record['table_closed_at']);
        $this->getJson('/local-api/cash/payments/'.$record['id'])->assertOk()
            ->assertJsonPath('order.id', $order)->assertJsonPath('order.totalCents', 4500)
            ->assertJsonCount(2, 'order.items')->assertJsonPath('payment.shift_id', $shift);
        $this->getJson('/local-api/cash/payments?table=Mesa%201&method=cash&shift=Ma%C3%B1ana&from=2026-11-07&to=2026-11-07')
            ->assertOk()->assertJsonPath('records.total', 1)->assertJsonPath('total_cents', 4500);
        $this->getJson('/local-api/cash/payments?method=yape')->assertOk()->assertJsonPath('total_cents', 4700);
        DB::table('orders')->where('id', $other)->update(['delivery_method' => 'table', 'table_label' => 'Mesa 10']);
        $this->getJson('/local-api/cash/payments?table=Mesa%201')->assertOk()
            ->assertJsonPath('records.total', 1)->assertJsonPath('records.data.0.order_id', $order)
            ->assertJsonPath('tables', ['Mesa 1', 'Mesa 10'])->assertJsonPath('shifts', ['Mañana']);
        $this->getJson('/local-api/cash/payments?table=Mesa%2010')->assertOk()->assertJsonPath('records.data.0.order_id', $other);
        $this->getJson('/local-api/cash/payments?shift=Ma')->assertOk()->assertJsonPath('records.total', 0);

        $this->getJson('/local-api/cash/payments?table=NO_EXISTE')->assertOk()->assertJsonCount(0, 'records.data');
        $this->getJson('/local-api/cash/payments?table=%25')->assertOk()->assertJsonCount(0, 'records.data');
        $this->getJson('/local-api/cash/payments?from=2026-11-08&to=2026-11-07')->assertUnprocessable();
        $this->getJson('/local-api/cash/payments?page=0')->assertUnprocessable();
        $this->getJson('/local-api/cash/payments/999999999')->assertNotFound();
        $this->actingAs($this->staff('cashier'))->getJson('/local-api/cash/payments/'.$record['id'])->assertOk();
        // Lima's date boundary is UTC 05:00, including the entire local end date.
        DB::table('order_payments')->where('id', $record['id'])->update(['paid_at' => '2026-11-08 04:59:59']);
        $this->getJson('/local-api/cash/payments?method=cash&from=2026-11-07&to=2026-11-07')
            ->assertOk()->assertJsonPath('records.total', 1);
        DB::table('order_payments')->where('id', $record['id'])->update(['paid_at' => '2026-11-08 05:00:00']);
        $this->getJson('/local-api/cash/payments?method=cash&to=2026-11-07')->assertOk()->assertJsonPath('records.total', 0);
        DB::table('payment_methods')->where('code', 'cash')->update(['active' => false]);
        $this->getJson('/local-api/cash/payments?method=cash')->assertOk()->assertJsonPath('records.total', 1)
            ->assertJsonFragment(['method' => 'cash', 'method_name' => 'Efectivo']);
        foreach (['waiter', 'kitchen', 'stock'] as $role) {
            $this->actingAs($this->staff($role))->getJson('/local-api/cash/payments')->assertForbidden();
            $this->getJson('/local-api/cash/payments/'.$record['id'])->assertForbidden();
        }

    }

    public function test_inventory_uses_batch_portions_without_debiting_ingredients_twice(): void
    {
        $this->actingAs($this->staff('stock'));
        $this->getJson('/local-api/cash')->assertForbidden();
        $unit = DB::table('inventory_units')->first()->id;
        $item = $this->postJson('/local-api/inventory/master/items', ['name' => 'Arroz', 'unit_id' => $unit, 'minimum_milli' => 1000])->assertOk()->json('id');
        $supplier = $this->postJson('/local-api/inventory/master/suppliers', ['name' => 'Mercado'])->assertOk()->json('id');
        $buy = ['request_id' => (string) Str::uuid(), 'supplier_id' => $supplier,
            'lines' => [['item_id' => $item, 'quantity_milli' => 10000, 'cost_cents' => 1000]]];
        $this->postJson('/local-api/inventory/purchases', $buy)->assertOk();
        $this->postJson('/local-api/inventory/purchases', $buy)->assertOk();
        $this->assertDatabaseHas('inventory_items', ['id' => $item, 'stock_milli' => 10000]);
        $this->postJson('/local-api/inventory/recipes', ['product_key' => 'carta:arroz', 'mode' => 'batch',
            'components' => [['item_id' => $item, 'quantity_milli' => 1000]]])->assertOk();
        $recipe = DB::table('inventory_recipes')->where('product_key', 'carta:arroz')->value('id');
        $batch = $this->postJson('/local-api/inventory/batches', ['request_id' => (string) Str::uuid(),
            'recipe_id' => $recipe, 'portions' => 5])->assertOk()->json('id');
        $order = $this->postJson('/local-api/orders', $this->payload())->assertCreated()->json('order.id');
        $this->postJson('/local-api/inventory/orders/'.$order.'/consume')->assertUnprocessable();
        $this->actingAs($this->staff('kitchen'));
        foreach (['kitchen'] as $center) {
            $this->advanceTicket($order, $center, 'pending')->assertOk();
            $this->advanceTicket($order, $center, 'preparing')->assertOk();
        }
        $this->actingAs($this->staff('stock'));
        $consume = '/local-api/inventory/orders/'.$order.'/consume';
        $this->postJson($consume)->assertOk()->assertJsonPath('consumption.covered_lines', 1)->assertJsonPath('consumption.cost_cents', 200);
        $this->postJson($consume)->assertOk();
        $this->assertDatabaseCount('inventory_consumptions', 1);
        $this->assertDatabaseHas('inventory_items', ['id' => $item, 'stock_milli' => 5000, 'stock_value_cents' => 500]);
        $this->getJson('/local-api/inventory')->assertOk()->assertJsonPath('batches.data.0.remaining', 3);
        $this->postJson('/local-api/inventory/movements', ['request_id' => (string) Str::uuid(),
            'type' => 'merma', 'batch_id' => $batch, 'quantity_milli' => 1000, 'reason' => 'Porción dañada'])->assertOk();
        $this->assertDatabaseHas('inventory_batches', ['id' => $batch, 'remaining' => 2, 'remaining_cost_cents' => 200]);

        $completePayload = $this->payload();
        $completePayload['items'] = [['productId' => 'arroz', 'quantity' => 1]];
        $completePayload['expectedTotalCents'] = 2100;
        $complete = $this->postJson('/local-api/orders', $completePayload)->assertCreated()->json('order.id');
        $this->actingAs($this->staff());
        $this->advanceTicket($complete, 'kitchen', 'pending')->assertOk();
        $this->advanceTicket($complete, 'kitchen', 'preparing')->assertOk();
        $this->postJson('/local-api/inventory/orders/'.$complete.'/consume')->assertOk();
        $this->postJson('/local-api/cash/open', ['name' => 'Prueba', 'opening_cents' => 0])->assertOk();
        foreach ([$order => 4700, $complete => 2100] as $id => $amount) {
            $this->postJson('/local-api/cash/orders/'.$id.'/payment', ['method' => 'cash', 'expected_total_cents' => $amount])->assertOk();
        }
        $this->getJson('/local-api/reports/operations?from=2026-11-07&to=2026-11-07')
            ->assertOk()->assertJsonPath('foodCost.cost_cents', 100)->assertJsonPath('foodCost.sales_cents', 2000)
            ->assertJsonPath('foodCost.orders', 1)->assertJsonPath('foodCost.partial', 1);
    }

    public function test_sales_reports_use_paid_orders_and_group_daily_menus_once(): void
    {
        $range = '/local-api/reports/sales?from=2026-11-07&to=2026-11-07';
        $this->getJson($range.'&group=summary')->assertUnauthorized();
        $this->postJson('/local-api/orders', $this->payload())->assertCreated();
        $table = $this->payload();
        $table['customer'] = ['method' => 'table', 'tableLabel' => 'Mesa 1'];
        $table['expectedTotalCents'] = 3800;
        $table['items'] = [
            ['productId' => 'menu-dia__tallarin__ceviche__economico', 'quantity' => 1],
            ['productId' => 'menu-dia__tallarin__sopa__economico', 'quantity' => 1],
            ['productId' => 'menu-dia__tallarin__sopa__ejecutivo', 'quantity' => 1],
        ];
        $waiter = $this->staff('waiter');
        $this->actingAs($waiter);
        $menuOrder = $this->postJson('/local-api/staff-orders', $table)->assertCreated()->json('order.id');
        $this->getJson($range.'&group=article')->assertForbidden();
        $webOrder = $this->postJson('/local-api/orders', $this->payload())->assertCreated()->json('order.id');
        $cashier = $this->staff('cashier');
        $this->actingAs($cashier);
        $this->postJson('/local-api/cash/open', ['name' => 'Turno', 'opening_cents' => 0])->assertOk();
        foreach ([$menuOrder => 3800, $webOrder => 4700] as $id => $amount) {
            $this->postJson('/local-api/cash/orders/'.$id.'/payment', ['method' => 'cash', 'expected_total_cents' => $amount])->assertOk();
        }
        DB::table('menu_products')->where('item_key', 'carta:arroz')->update(['price_cents' => 3300]);
        $this->actingAs($this->staff());
        $this->getJson($range.'&group=summary')->assertOk()
            ->assertJsonPath('days.0.day', '2026-11-07')->assertJsonPath('days.0.orders', 2)
            ->assertJsonPath('days.0.amount_cents', 8500)->assertJsonPath('orders.0.count', 3);
        $this->getJson($range.'&group=article')->assertOk()->assertJsonCount(4, 'rows')
            ->assertJsonPath('rows.0.name', 'Arroz con mariscos')->assertJsonPath('rows.0.amount_cents', 4000)
            ->assertJsonPath('rows.1.name', 'Menú Económico')->assertJsonPath('rows.1.units', 2)
            ->assertJsonPath('rows.1.amount_cents', 2400)
            ->assertJsonFragment(['day' => '2026-11-07', 'series' => 'menu:Menú Económico', 'amount_cents' => 2400]);
        $this->getJson($range.'&group=category&category=menu-dia')->assertOk()->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.units', 3)->assertJsonPath('rows.0.amount_cents', 3800);
        $this->getJson($range.'&group=article&start=11:01')->assertOk()->assertJsonCount(0, 'rows');
        $this->getJson($range.'&group=article&start=11:00&end=11:00&attribution=cashier&staff='.$cashier->id)
            ->assertOk()->assertJsonCount(4, 'rows');
        $this->getJson($range.'&group=article&attribution=order&staff='.$waiter->id)->assertOk()->assertJsonCount(2, 'rows');
        $this->getJson($range.'&group=article&attribution=order&staff=0')->assertOk()->assertJsonCount(2, 'rows');
    }

    public function test_admin_can_rename_a_category_and_route_new_tickets(): void
    {
        $path = '/local-api/operations-settings/categories';
        $old = $this->postJson('/local-api/orders', $this->payload())->assertCreated()->json('order.id');
        $body = ['category' => 'marinos', 'name' => 'Especialidades marinas', 'center' => 'kitchen'];
        $this->actingAs($this->staff('waiter'))->postJson($path, $body)->assertForbidden();
        $this->actingAs($this->staff())->postJson($path, $body)->assertOk()
            ->assertJsonPath('categoryLabels.marinos', 'Especialidades marinas')
            ->assertJsonFragment(['category' => 'marinos', 'center' => 'kitchen']);
        $this->postJson($path, [...$body, 'center' => 'inexistente'])->assertUnprocessable();
        $this->assertDatabaseHas('menu_products', ['source_id' => 'arroz', 'category_id' => 'marinos']);
        $this->getJson('/local-api/catalog')->assertOk()->assertJsonPath('categoryLabels.marinos', 'Especialidades marinas');
        $this->getJson('/local-api/reports/sales?group=category&from=2026-11-07&to=2026-11-07')
            ->assertOk()->assertJsonPath('categoryLabels.marinos', 'Especialidades marinas');
        $this->assertDatabaseHas('production_tickets', ['order_id' => $old, 'center' => 'kitchen']);
        $new = $this->postJson('/local-api/orders', $this->payload())->assertCreated()->json('order.id');
        $this->assertDatabaseHas('production_tickets', ['order_id' => $new, 'center' => 'kitchen']);
        $this->assertSame(1, DB::table('production_tickets')->where('order_id', $new)->count());
        $this->assertDatabaseHas('operations_audit', ['action' => 'config.categoria', 'entity_id' => 'marinos']);
    }

    public function test_a_modifier_can_be_renamed_without_editing_existing_comandas(): void
    {
        $this->actingAs($this->staff('waiter'));
        $id = $this->postJson('/local-api/order-modifiers', ['name' => 'Salsa aparte'])->assertOk()->json('modifiers.0.id');
        $this->postJson('/local-api/order-modifiers', ['name' => 'Sin cebolla'])->assertOk();
        $order = $this->payload();
        $order['items'][0]['modifiers'] = ['Salsa aparte'];
        $saved = $this->postJson('/local-api/staff-orders', $order)->assertCreated()->json('order.id');
        $this->patchJson('/local-api/order-modifiers/'.$id, ['name' => 'sin cebolla'])->assertConflict();
        $this->patchJson('/local-api/order-modifiers/'.$id, ['name' => 'Salsa en recipiente'])->assertOk()
            ->assertJsonFragment(['id' => $id, 'name' => 'Salsa en recipiente']);
        $this->getJson('/local-api/orders/'.$saved)->assertOk()->assertJsonFragment(['modifiers' => ['Salsa aparte']]);
        $this->assertDatabaseHas('operations_audit', ['action' => 'salon.modificador_editado', 'entity_id' => (string) $id]);
    }

    private function advanceTicket(int $id, string $center, string $status, int $revision = 0): \Illuminate\Testing\TestResponse
    {
        return $this->patchJson('/local-api/orders/'.$id.'/tickets/'.$center, ['expectedStatus' => $status, 'expectedRevision' => $revision]);
    }
}
