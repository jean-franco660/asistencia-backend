<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supervisor_institucion', function (Blueprint $table) {
            $table->id();

            $table->foreignId('usuario_web_id')
                ->constrained('usuarios_web')
                ->cascadeOnDelete();

            $table->foreignId('institucion_id')
                ->constrained('instituciones')
                ->cascadeOnDelete();

            // Periodo de supervisión
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();

            $table->timestamps();

            // Evita duplicados de asignación
            $table->unique(['usuario_web_id', 'institucion_id'], 'uk_supervisor_ie');

            $table->index('usuario_web_id', 'idx_supervisor');
            $table->index('institucion_id', 'idx_institucion');
            $table->index(['institucion_id', 'fecha_inicio', 'fecha_fin'], 'idx_ie_periodo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_institucion');
    }
};
