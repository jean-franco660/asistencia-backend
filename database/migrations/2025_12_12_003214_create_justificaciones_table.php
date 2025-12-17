<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('justificaciones', function (Blueprint $table) {
            $table->id();

            // Relación con asistencia (puede ser null para faltas completas)
            $table->foreignId('asistencia_id')
                ->nullable()
                ->constrained('asistencias')
                ->cascadeOnDelete();

            // Usuario que justifica (siempre requerido)
            $table->foreignId('usuario_app_id')
                ->constrained('usuarios_app')
                ->cascadeOnDelete();

            // Institución donde ocurrió (siempre requerido)
            $table->foreignId('institucion_id')
                ->constrained('instituciones')
                ->cascadeOnDelete();

            // Horario/turno específico (opcional)
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
                'OTRO'
            ]);

            // Periodo de la justificación
            $table->date('fecha_inicio');
            $table->date('fecha_fin');

            // Motivo detallado
            $table->text('motivo');

            // Estado de la justificación
            $table->enum('estado', ['PENDIENTE', 'APROBADO', 'RECHAZADO'])
                ->default('PENDIENTE');

            // ✅ CORREGIDO: Revisor (con nullOnDelete para soft deletes)
            $table->foreignId('usuario_web_id')
                ->nullable()
                ->constrained('usuarios_web')
                ->nullOnDelete(); // Si se elimina el revisor, mantener la justificación

            // Observaciones del revisor
            $table->text('observaciones')->nullable();
            
            // Fecha de revisión
            $table->timestamp('fecha_revision')->nullable();

            $table->timestamps();

            // ===================================================
            // ÍNDICES PARA OPTIMIZACIÓN DE CONSULTAS
            // ===================================================

            // Buscar justificaciones por usuario y fecha
            $table->index(['usuario_app_id', 'fecha_inicio'], 'idx_usuario_fecha_inicio');
            
            // Filtrar justificaciones por institución y estado
            $table->index(['institucion_id', 'estado'], 'idx_institucion_estado');
            
            // Filtrar por tipo de justificación
            $table->index('tipo', 'idx_tipo');
            
            // ✅ NUEVO: Buscar justificaciones pendientes de revisión
            $table->index(['estado', 'fecha_inicio'], 'idx_estado_fecha');
            
            // ✅ NUEVO: Justificaciones por revisor
            $table->index('usuario_web_id', 'idx_revisor');
            
            // ✅ NUEVO: Rango de fechas (para reportes)
            $table->index(['fecha_inicio', 'fecha_fin'], 'idx_rango_fechas');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('justificaciones');
    }
};