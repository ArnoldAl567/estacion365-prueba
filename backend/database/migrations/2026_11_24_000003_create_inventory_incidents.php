<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_incidents', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_id')->unique();
            $t->string('kind', 12);
            $t->foreignId('item_id')->constrained('inventory_items');
            $t->bigInteger('system_milli')->nullable();
            $t->bigInteger('system_value_cents')->nullable();
            $t->bigInteger('counted_milli')->nullable();
            $t->bigInteger('delta_milli');
            $t->bigInteger('cost_cents');
            $t->unsignedBigInteger('snapshot_movement_id')->nullable();
            $t->string('reason', 180);
            $t->string('notes', 500)->nullable();
            $t->string('state', 12)->default('pending');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('applied_by')->nullable()->constrained('users');
            $t->foreignId('cancelled_by')->nullable()->constrained('users');
            $t->foreignId('movement_id')->nullable()->constrained('inventory_movements');
            $t->foreignId('reversal_id')->nullable()->constrained('inventory_movements');
            $t->timestamp('created_at')->index();
            $t->timestamp('applied_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancellation_reason', 180)->nullable();
            $t->index(['kind', 'state']);
        });
        DB::statement("ALTER TABLE inventory_incidents ADD CHECK (kind IN ('merma','desvio') AND state IN ('pending','applied','cancelled') AND delta_milli != 0)");
        // Import recorded losses as already applied; never deduct their stock a second time.
        DB::statement("INSERT INTO inventory_incidents (request_id,kind,item_id,delta_milli,cost_cents,reason,state,created_by,applied_by,movement_id,created_at,applied_at)
            SELECT md5('legacy-merma-' || id)::uuid,'merma',item_id,quantity_milli,cost_cents,coalesce(reason,'Merma registrada anteriormente'),'applied',user_id,user_id,id,created_at,created_at
            FROM inventory_movements WHERE type='merma' AND item_id IS NOT NULL AND quantity_milli < 0");
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_incidents');
    }
};
