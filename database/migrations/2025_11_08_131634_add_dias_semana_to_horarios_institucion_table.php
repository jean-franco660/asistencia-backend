<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('horarios_institucion', function (Blueprint $table) {
            $table->json('dias_semana')->nullable()->after('tolerancia_minutos');
        });
    }

    public function down(): void
    {
        Schema::table('horarios_institucion', function (Blueprint $table) {
            $table->dropColumn('dias_semana');
        });
    }
};
