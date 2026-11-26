<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_units', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80)->unique();
            $t->string('symbol', 16)->unique();
        });
        Schema::create('inventory_suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120)->unique();
            $t->string('contact', 120)->nullable();
        });
        Schema::create('inventory_items', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120)->unique();
            $t->foreignId('unit_id')->constrained('inventory_units');
            $t->string('category', 80)->nullable();
            $t->unsignedBigInteger('minimum_milli')->default(0);
            $t->unsignedBigInteger('stock_milli')->default(0);
            $t->unsignedBigInteger('stock_value_cents')->default(0);
        });
        Schema::create('inventory_purchases', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_id')->unique();
            $t->foreignId('supplier_id')->constrained('inventory_suppliers');
            $t->foreignId('user_id')->constrained();
            $t->string('reference', 100)->nullable();
            $t->unsignedBigInteger('total_cents');
            $t->timestamp('created_at')->index();
        });
        Schema::create('inventory_recipes', function (Blueprint $t) {
            $t->id();
            $t->string('product_key')->unique();
            $t->foreign('product_key')->references('item_key')->on('menu_products');
            $t->string('mode', 12);
            $t->jsonb('components');
        });
        Schema::create('inventory_batches', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_id')->unique();
            $t->foreignId('recipe_id')->constrained('inventory_recipes');
            $t->foreignId('user_id')->constrained();
            $t->unsignedInteger('portions');
            $t->unsignedInteger('remaining');
            $t->unsignedBigInteger('cost_cents');
            $t->unsignedBigInteger('remaining_cost_cents');
            $t->timestamp('created_at')->index();
        });
        Schema::create('inventory_consumptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->unique()->constrained();
            $t->foreignId('user_id')->constrained();
            $t->unsignedBigInteger('cost_cents');
            $t->unsignedInteger('covered_lines');
            $t->unsignedInteger('total_lines');
            $t->timestamp('created_at')->index();
        });
        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_id')->nullable()->unique();
            $t->foreignId('item_id')->nullable()->constrained('inventory_items');
            $t->foreignId('batch_id')->nullable()->constrained('inventory_batches');
            $t->foreignId('purchase_id')->nullable()->constrained('inventory_purchases');
            $t->foreignId('order_id')->nullable()->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('type', 24);
            $t->bigInteger('quantity_milli');
            $t->bigInteger('cost_cents');
            $t->string('reason', 180)->nullable();
            $t->timestamp('created_at')->index();
        });
        DB::table('inventory_units')->insert([
            ['name' => 'Kilogramo', 'symbol' => 'kg'],
            ['name' => 'Litro', 'symbol' => 'l'],
            ['name' => 'Unidad', 'symbol' => 'u'],
        ]);
    }

    public function down(): void
    {
        foreach (['inventory_movements', 'inventory_consumptions', 'inventory_batches', 'inventory_recipes',
            'inventory_purchases', 'inventory_items', 'inventory_suppliers', 'inventory_units'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
