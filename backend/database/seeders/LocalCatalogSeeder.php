<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocalCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = json_decode(file_get_contents(database_path('data/catalog.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($catalog['items'] as $item) {
            DB::table('menu_products')->insertOrIgnore([
                'item_key' => $item['item_key'], 'source_id' => $item['source_id'], 'kind' => $item['kind'],
                'category_id' => $item['category_id'], 'name' => $item['name'],
                'price_cents' => $item['price'] === null ? null : (int) round($item['price'] * 100),
                'image_url' => $item['image_url'], 'active' => $item['active'], 'sort_order' => $item['sort_order'],
            ]);
        }
        foreach ($catalog['prices'] as $type => $amount) {
            DB::table('menu_prices')->insertOrIgnore(['type' => $type, 'price_cents' => (int) round($amount * 100)]);
        }
    }
}
