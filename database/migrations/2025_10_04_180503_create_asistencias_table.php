<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('asistencias', function (Blueprint $table) {
            $table->id();

            // Quién y dónde (cabecera diaria)
            $table->foreignId('usuario_app_id')
                ->constrained('usuarios_app');

            $table->foreignId('institucion_id')
                ->constrained('instituciones');

            $table->foreignId('horario_institucion_id')
                ->nullable()
                ->constrained('horarios_institucion');

            // Día de asistencia
            $table->date('fecha')->index();

            /**
             * Estado diario (resultado del día)
             * Ejemplos: PRESENTE / TARDANZA / FALTA / JUSTIFICADO / OBSERVADO
             * (string para flexibilidad; valida en backend con Rule::in([...]))
             */
            $table->string('estado_diario', 20)->default('FALTA')->index();

            /**
             * Resumen del día (opcional)
             * - hora_entrada/hora_salida: calculadas a partir de marcaciones
             * - minutos_tardanza: calculado si aplica
             */
            $table->time('hora_entrada')->nullable();
            $table->time('hora_salida')->nullable();
            $table->unsignedInteger('minutos_tardanza')->nullable();

            // Observaciones del sistema o del revisor (opcional)
            $table->text('observacion')->nullable();

            // Auditoría de revisión (si lo manejas en web)
            $table->foreignId('revisado_por_usuario_web_id')
                ->nullable()
                ->constrained('usuarios_web'); // si existe esta tabla en tu backend

            $table->timestamp('revisado_at')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            /**
             * Una cabecera por docente+IE+fecha
             */
            $table->unique(
                ['usuario_app_id', 'institucion_id', 'fecha'],
                'uq_asistencias_usuario_institucion_fecha'
            );

            // Índices útiles para reportes
            $table->index(['institucion_id', 'fecha'], 'idx_asistencias_institucion_fecha');
            $table->index(['usuario_app_id', 'fecha'], 'idx_asistencias_usuario_fecha');
            $table->index(['institucion_id', 'fecha', 'estado_diario'], 'idx_asistencias_institucion_fecha_estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
    }
};