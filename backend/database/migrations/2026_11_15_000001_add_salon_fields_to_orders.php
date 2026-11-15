<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('table_label', 40)->nullable();
            $table->string('source', 12)->default('web');
            $table->unsignedInteger('revision')->default(0);
            $table->string('payment_method', 16)->nullable()->change();
        });
        Schema::create('order_modifiers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->string('normalized_name', 120)->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_modifiers');
        DB::table('orders')->whereNull('payment_method')->update(['payment_method' => 'unspecified']);
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['table_label', 'source', 'revision']);
            $table->string('payment_method', 16)->nullable(false)->change();
        });
    }
};
