<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80);
        });
        DB::statement('CREATE UNIQUE INDEX inventory_categories_name_unique ON inventory_categories (lower(trim(name)))');
        Schema::table('inventory_items', fn (Blueprint $t) => $t->foreignId('category_id')->nullable()->constrained('inventory_categories'));
        DB::statement("INSERT INTO inventory_categories (name) SELECT min(trim(category)) FROM inventory_items WHERE category IS NOT NULL AND trim(category) != '' GROUP BY lower(trim(category))");
        DB::statement('UPDATE inventory_items i SET category_id = c.id, category = c.name FROM inventory_categories c WHERE lower(trim(i.category)) = lower(c.name)');
    }
    public function down(): void
    {
        Schema::table('inventory_items', fn (Blueprint $t) => $t->dropConstrainedForeignId('category_id'));
        Schema::dropIfExists('inventory_categories');
    }
};
