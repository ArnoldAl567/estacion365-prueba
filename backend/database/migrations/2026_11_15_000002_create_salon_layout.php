<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salon_layout', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->unsignedInteger('revision')->default(0);
            $table->jsonb('zones');
        });

        $zones = [];
        foreach (['Salón A' => [1, 2, 3, 5, 6, 7, 8], 'Salón B' => [4, 9, 10, 11, 12, 13, 14, 15]] as $name => $tables) {
            $zones[] = ['id' => (string) Str::uuid(), 'name' => $name, 'tables' => array_map(
                fn ($number) => ['id' => (string) Str::uuid(), 'label' => 'M'.$number],
                $tables,
            )];
        }
        DB::table('salon_layout')->insert(['id' => 1, 'zones' => json_encode($zones, JSON_THROW_ON_ERROR)]);
    }

    public function down(): void
    {
        Schema::dropIfExists('salon_layout');
    }
};
