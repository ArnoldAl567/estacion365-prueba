<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('cash_shift_templates',function(Blueprint $t){$t->id();$t->string('name',80);$t->boolean('active')->default(true);});
        DB::statement('CREATE UNIQUE INDEX cash_shift_templates_name_unique ON cash_shift_templates (lower(trim(name)))');
        foreach(['Turno Mañana','Turno Tarde','Turno Noche'] as $name) DB::table('cash_shift_templates')->insert(['name'=>$name,'active'=>true]);
        Schema::table('cash_shifts',fn(Blueprint $t)=>$t->foreignId('template_id')->nullable()->constrained('cash_shift_templates'));
        DB::statement('UPDATE cash_shifts s SET template_id=t.id FROM cash_shift_templates t WHERE lower(trim(s.name))=lower(t.name)');
    }
    public function down(): void {Schema::table('cash_shifts',fn(Blueprint $t)=>$t->dropConstrainedForeignId('template_id'));Schema::dropIfExists('cash_shift_templates');}
};
