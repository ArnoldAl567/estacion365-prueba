<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inventory_purchases', function (Blueprint $t) {
            $t->timestamp('cancelled_at')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users');
            $t->string('cancellation_reason', 180)->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('inventory_purchases', function (Blueprint $t) {
            $t->dropConstrainedForeignId('cancelled_by');
            $t->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
