<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuario_app_institucion', function (Blueprint $table) {
            $table->id();

            $table->foreignId('usuario_app_id')
                  ->constrained('usuarios_app')
                  ->cascadeOnDelete();

            $table->foreignId('institucion_id')
                  ->constrained('instituciones')
                  ->restrictOnDelete();

            // Turno/horario asignado
            $table->foreignId('horario_institucion_id')
                  ->constrained('horarios_institucion')
                  ->restrictOnDelete();

            // Cargo del usuario en esta institución
            $table->string('cargo', 50)->nullable();
            
            // Estado de la asignación
            $table->enum('estado', ['ACTIVO', 'INACTIVO'])->default('ACTIVO');
            
            // Periodo de vigencia
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();

            $table->timestamps();

            // Constraint: No duplicar usuario + institución + horario
            $table->unique(
                ['usuario_app_id', 'institucion_id', 'horario_institucion_id'],
                'uk_usuario_ie_horario'
            );

            // Índices para consultas frecuentes
            $table->index(['institucion_id', 'estado'], 'idx_ie_estado');
            $table->index(['usuario_app_id', 'estado'], 'idx_usuario_estado');
            $table->index('cargo', 'idx_cargo');
            $table->index(['fecha_fin', 'estado'], 'idx_vencimiento');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_app_institucion');
    }
};