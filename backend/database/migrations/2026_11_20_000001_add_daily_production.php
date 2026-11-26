<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inventory_recipes', fn (Blueprint $t) => $t->unsignedInteger('batch_yield')->default(1));
        Schema::create('inventory_daily_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('recipe_id')->constrained('inventory_recipes');
            $t->date('day');
            $t->unsignedInteger('direct_portions');
            $t->unsignedInteger('batch_count');
            $t->unsignedInteger('batch_yield');
            $t->unsignedInteger('revision')->default(0);
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
            $t->unique(['recipe_id', 'day']);
            $t->index('day');
        });
        Schema::create('inventory_portion_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_id')->constrained('inventory_daily_plans');
            $t->foreignId('order_id')->constrained();
            $t->foreignId('batch_id')->nullable()->constrained('inventory_batches');
            $t->unsignedInteger('portions');
            // Snapshot protects already confirmed orders from subsequent recipe edits.
            $t->jsonb('components');
            $t->string('state', 16)->default('reserved');
            $t->unsignedBigInteger('cost_cents')->default(0);
            $t->timestamps();
            $t->index(['plan_id', 'state']);
            $t->index(['order_id', 'state']);
            $t->index(['batch_id', 'state']);
        });
        DB::statement('ALTER TABLE inventory_daily_plans ADD CHECK (direct_portions >= 0 AND batch_count >= 0 AND batch_yield > 0)');
        DB::statement("ALTER TABLE inventory_portion_reservations ADD CHECK (portions > 0 AND state IN ('reserved', 'consumed', 'released', 'wasted'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_portion_reservations');
        Schema::dropIfExists('inventory_daily_plans');
        Schema::table('inventory_recipes', fn (Blueprint $t) => $t->dropColumn('batch_yield'));
    }
};
