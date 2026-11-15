<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('webp_base64');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_images');
    }
};
