<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 40)->unique();
            $table->string('role', 16)->default('kitchen');
            $table->boolean('active')->default(true);
        });

        Schema::create('menu_products', function (Blueprint $table) {
            $table->string('item_key')->primary();
            $table->string('source_id');
            $table->string('kind', 16);
            $table->string('category_id')->nullable();
            $table->string('name');
            $table->unsignedInteger('price_cents')->nullable();
            $table->text('image_url')->nullable();
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->unique(['kind', 'source_id']);
        });

        Schema::create('menu_prices', function (Blueprint $table) {
            $table->string('type')->primary();
            $table->unsignedInteger('price_cents');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->uuid('tracking_id')->unique();
            $table->string('request_hash', 64);
            $table->string('customer_name', 120);
            $table->string('customer_phone', 9);
            $table->string('delivery_method', 16);
            $table->string('payment_method', 16);
            $table->string('address', 240)->nullable();
            $table->string('reference', 160)->nullable();
            $table->jsonb('items');
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('packaging_cents');
            $table->unsignedInteger('total_cents');
            $table->string('status', 16)->default('pending')->index();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('menu_prices');
        Schema::dropIfExists('menu_products');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['username', 'role', 'active']));
    }
};
