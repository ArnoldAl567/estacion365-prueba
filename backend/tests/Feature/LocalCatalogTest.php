<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocalCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'estacion_operaciones_test') {
            throw new \RuntimeException('Las pruebas solo pueden ejecutarse en estacion_operaciones_test.');
        }
    }

    private function staff(string $role): User
    {
        return User::create(['name' => 'Prueba', 'username' => $role, 'email' => $role.'@local.test',
            'password' => 'Local-test-365!', 'role' => $role, 'active' => true]);
    }

    private function item(string $kind = 'carta'): array
    {
        return ['source_id' => 'nuevo-'.Str::uuid(), 'kind' => $kind, 'name' => 'Plato de prueba',
            'category_id' => $kind === 'carta' ? 'marinos' : null, 'price' => $kind === 'carta' ? 15.5 : null,
            'image_url' => 'https://example.com/foto.webp', 'active' => true];
    }

    public function test_only_administrators_can_modify_the_local_catalog(): void
    {
        $this->postJson('/local-api/catalog/items', $this->item())->assertUnauthorized();
        $this->actingAs($this->staff('kitchen'));
        $this->postJson('/local-api/catalog/items', $this->item())->assertForbidden();
        $this->patchJson('/local-api/catalog/items/carta:arroz', ['active' => false])->assertForbidden();
        $this->putJson('/local-api/catalog/prices', ['economico' => 13, 'ejecutivo' => 15])->assertForbidden();
        $this->postJson('/local-api/catalog/images')->assertForbidden();
        $this->assertDatabaseCount('menu_products', 0);
    }

    public function test_admin_can_create_each_kind_and_edit_names_prices_and_availability(): void
    {
        $this->actingAs($this->staff('admin'));
        foreach (['carta', 'fondo', 'entrada'] as $kind) {
            $data = $this->item($kind);
            $key = $kind.':'.$data['source_id'];
            $this->postJson('/local-api/catalog/items', $data)->assertCreated()
                ->assertJsonPath('item.item_key', $key)->assertJsonPath('item.kind', $kind);
            $this->assertDatabaseHas('menu_products', ['item_key' => $key, 'price_cents' => $kind === 'carta' ? 1550 : null]);
            $this->patchJson('/local-api/catalog/items/'.$key, ['name' => 'Nombre actualizado', 'active' => false])
                ->assertOk()->assertJsonPath('item.active', false)->assertJsonPath('item.name', 'Nombre actualizado');
            $this->patchJson('/local-api/catalog/items/'.$key, ['kind' => 'otro'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('menu_products', 3);
        $data = $this->item();
        foreach ([['category_id' => 'incorrecta'], ['price' => 1.234], ['image_url' => 'javascript:alert(1)'], ['name' => ' ']] as $invalid) {
            $this->postJson('/local-api/catalog/items', [...$data, ...$invalid])->assertUnprocessable();
        }
        $this->assertDatabaseCount('menu_products', 3);
    }

    public function test_capacity_is_250_including_unavailable_items_but_edits_remain_allowed(): void
    {
        $this->actingAs($this->staff('admin'));
        for ($index = 0; $index < 250; $index++) {
            DB::table('menu_products')->insert(['item_key' => 'carta:test-'.$index, 'source_id' => 'test-'.$index,
                'kind' => 'carta', 'category_id' => 'marinos', 'name' => 'Plato '.$index, 'price_cents' => 500, 'active' => false]);
        }
        $this->postJson('/local-api/catalog/items', $this->item())->assertConflict();
        $this->patchJson('/local-api/catalog/items/carta:test-0', ['name' => 'Se puede editar', 'active' => true])->assertOk();
        try {
            DB::transaction(fn () => DB::table('menu_products')->insert(['item_key' => 'carta:extra', 'source_id' => 'extra', 'kind' => 'carta', 'name' => 'Extra']));
            $this->fail('PostgreSQL debe rechazar el producto 251.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
        // Reiniciar la aplicación no debe fallar al intentar insertar claves ya existentes.
        DB::table('menu_products')->insertOrIgnore(['item_key' => 'carta:test-0', 'source_id' => 'test-0', 'kind' => 'carta', 'name' => 'Duplicado']);
        $this->assertDatabaseCount('menu_products', 250);
    }

    public function test_daily_prices_are_atomic_and_public_catalog_uses_the_edits(): void
    {
        $this->actingAs($this->staff('admin'));
        $this->putJson('/local-api/catalog/prices', ['economico' => 13.5, 'ejecutivo' => 16])->assertOk();
        $this->putJson('/local-api/catalog/prices', ['economico' => 20, 'ejecutivo' => -1])->assertUnprocessable();
        $this->getJson('/local-api/catalog')->assertOk()->assertJsonPath('prices.economico', 13.5)->assertJsonPath('prices.ejecutivo', 16);
        $this->assertDatabaseHas('menu_prices', ['type' => 'economico', 'price_cents' => 1350]);
    }

    public function test_uploaded_photos_are_local_webp_optimized_and_cannot_be_arbitrary_files(): void
    {
        Storage::fake('public');
        $this->actingAs($this->staff('admin'));
        $photo = UploadedFile::fake()->image('foto.jpg', 1800, 1200);
        $response = $this->post('/local-api/catalog/images', ['image' => $photo], ['Accept' => 'application/json'])->assertOk();
        $url = $response->json('url');
        $this->assertStringStartsWith('/local-api/images/', $url);
        $filename = basename($url);
        Storage::disk('public')->assertExists('catalog/'.$filename);
        $binary = Storage::disk('public')->get('catalog/'.$filename);
        $this->assertLessThanOrEqual(180 * 1024, strlen($binary));
        $dimensions = getimagesizefromstring($binary);
        $this->assertLessThanOrEqual(1200, max($dimensions[0], $dimensions[1]));
        $this->assertSame(IMAGETYPE_WEBP, $dimensions[2]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->postJson('/local-api/catalog/items', [...$this->item(), 'image_url' => $url])->assertCreated();
        $this->post('/local-api/catalog/images', ['image' => UploadedFile::fake()->createWithContent('falsa.jpg', '<?php echo 1;')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->postJson('/local-api/catalog/items', [...$this->item(), 'image_url' => '/local-api/images/'.Str::uuid().'.webp'])->assertUnprocessable();
    }
}
