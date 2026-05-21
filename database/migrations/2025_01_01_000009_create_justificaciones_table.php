<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('justificaciones', function (Blueprint $table) {
            $table->id();

            // Relación con asistencia (puede ser null para faltas completas)
            $table->foreignId('asistencia_id')
                ->nullable()
                ->constrained('asistencias')
                ->cascadeOnDelete();

            // Usuario que justifica
            $table->foreignId('usuario_app_id')
                ->constrained('usuarios_app')
                ->cascadeOnDelete();

            // Institución donde ocurrió
            $table->foreignId('institucion_id')
                ->constrained('instituciones')
                ->cascadeOnDelete();

            // Horario/turno específico
            $table->foreignId('horario_institucion_id')
                ->nullable()
                ->constrained('horarios_institucion')
                ->nullOnDelete();

            // Tipo de justificación
            $table->enum('tipo', [
                'ENFERMEDAD',
                'PERMISO_PERSONAL',
                'LICENCIA',
                'COMISION_SERVICIO',
                'CAPACITACION',
                'DUELO',
                'MATERNIDAD',
                'PATERNIDAD',
                'OLVIDO_MARCACION',
                'OTRO',
            ]);

            // Periodo
            $table->date('fecha_inicio');
            $table->date('fecha_fin');

            // Motivo detallado
            $table->text('motivo');

            // Estado
            $table->enum('estado', ['PENDIENTE', 'APROBADO', 'RECHAZADO'])
                ->default('PENDIENTE');

            // Revisor
            $table->foreignId('usuario_web_id')
                ->nullable()
                ->constrained('usuarios_web')
                ->nullOnDelete();

            $table->text('observaciones')->nullable();
            $table->timestamp('fecha_revision')->nullable();

            $table->timestamps();

            // Índices para consultas frecuentes
            $table->index(['usuario_app_id', 'fecha_inicio'], 'idx_usuario_fecha_inicio');
            $table->index(['institucion_id', 'estado'], 'idx_institucion_estado');
            $table->index('tipo', 'idx_tipo');
            $table->index(['estado', 'fecha_inicio'], 'idx_estado_fecha');
            $table->index('usuario_web_id', 'idx_revisor');
            $table->index(['fecha_inicio', 'fecha_fin'], 'idx_rango_fechas');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('justificaciones');
    }
};
