<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_centers', function (Blueprint $table) {
            $table->string('code', 32)->primary();
            $table->string('name', 80);
            $table->string('printer', 160)->nullable();
            $table->boolean('active')->default(true);
        });
        Schema::create('production_routes', function (Blueprint $table) {
            $table->string('category', 64)->primary();
            $table->string('center', 32);
            $table->foreign('center')->references('code')->on('production_centers');
        });
        Schema::create('production_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('center', 32)->index();
            $table->foreign('center')->references('code')->on('production_centers');
            $table->string('status', 16)->default('pending');
            $table->jsonb('items');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->unique(['order_id', 'center']);
        });
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->string('code', 32)->primary();
            $table->string('name', 80);
            $table->boolean('cash');
            $table->boolean('active')->default(true);
        });
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->foreignId('opened_by')->constrained('users');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->unsignedInteger('opening_cents');
            $table->integer('expected_cents')->nullable();
            $table->unsignedInteger('counted_cents')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
        });
        DB::statement('create unique index cash_shifts_single_open on cash_shifts ((1)) where closed_at is null');
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained();
            $table->foreignId('shift_id')->constrained('cash_shifts');
            $table->foreignId('user_id')->constrained();
            $table->string('method', 32);
            $table->string('method_name', 80);
            $table->boolean('cash');
            $table->unsignedInteger('amount_cents');
            $table->timestamp('paid_at')->index();
        });
        Schema::create('cash_expenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('shift_id')->constrained('cash_shifts');
            $table->foreignId('user_id')->constrained();
            $table->string('description', 180);
            $table->unsignedInteger('amount_cents');
            $table->timestamp('created_at')->index();
        });
        Schema::create('operations_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64)->index();
            $table->string('entity', 40);
            $table->string('entity_id', 64);
            $table->jsonb('details')->nullable();
            $table->timestamp('created_at')->index();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('table_closed_at')->nullable();
            $table->foreignId('table_closed_by')->nullable()->constrained('users');
            $table->boolean('cash_eligible')->default(false);
        });
        DB::table('orders')->whereIn('status', ['pending', 'preparing', 'ready'])->update(['cash_eligible' => true]);
        DB::statement('alter table orders alter column cash_eligible set default true');
        DB::table('orders')->where('delivery_method', 'table')->where('status', 'delivered')
            ->update(['table_closed_at' => DB::raw('updated_at')]);
        DB::table('production_centers')->insert([['code' => 'kitchen', 'name' => 'Cocina'], ['code' => 'bar', 'name' => 'Barra']]);
        DB::table('production_routes')->insert(['category' => 'bebidas', 'center' => 'bar']);
        DB::table('payment_methods')->insert([
            ['code' => 'cash', 'name' => 'Efectivo', 'cash' => true],
            ['code' => 'yape', 'name' => 'Yape', 'cash' => false],
        ]);
        DB::statement(<<<'SQL'
            insert into production_tickets (order_id, center, status, items, started_at, ready_at)
            select o.id, r.center,
              case when o.status = 'delivered' then 'ready' else o.status end,
              jsonb_agg(line.item order by line.position),
              case when o.status in ('preparing', 'ready', 'delivered') then o.updated_at end,
              case when o.status in ('ready', 'delivered') then o.updated_at end
            from orders o
            cross join lateral jsonb_array_elements(o.items) with ordinality line(item, position)
            cross join lateral (select case when line.item->>'categoryId' = 'bebidas' then 'bar' else 'kitchen' end as center) r
            group by o.id, r.center
        SQL);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('table_closed_by');
            $table->dropColumn(['table_closed_at', 'cash_eligible']);
        });
        foreach (['operations_audit', 'cash_expenses', 'order_payments', 'cash_shifts', 'payment_methods', 'production_tickets', 'production_routes', 'production_centers'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
