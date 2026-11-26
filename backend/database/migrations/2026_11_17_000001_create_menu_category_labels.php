<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_category_labels', function (Blueprint $table) {
            $table->string('category', 64)->primary();
            $table->string('name', 80);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_category_labels');
    }
};
