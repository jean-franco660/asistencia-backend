<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla `asistencias`, que funciona como cabecera diaria de asistencia por docente.
 * Existe un registro único por docente, institución y fecha. Almacena el estado final del día
 * (PRESENTE, TARDANZA, FALTA, etc.) calculado a partir de las marcaciones individuales
 * registradas en `asistencias_diarias`. Permite revisión por un usuario web.
 */
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

            // Estado diario (resultado del día)
            $table->string('estado_diario', 20)->default('FALTA')->index();

            // Resumen del día
            $table->time('hora_entrada')->nullable();
            $table->time('hora_salida')->nullable();
            $table->unsignedInteger('minutos_tardanza')->nullable();

            // Observaciones
            $table->text('observacion')->nullable();

            // Auditoría de revisión
            $table->foreignId('revisado_por_usuario_web_id')
                ->nullable()
                ->constrained('usuarios_web');
            $table->timestamp('revisado_at')->nullable();

            $table->timestamps();

            // Una cabecera por docente+IE+fecha
            $table->unique(
                ['usuario_app_id', 'institucion_id', 'fecha'],
                'uq_asistencia_usuario_ie_fecha'
            );

            // Índices para reportes
            $table->index(['institucion_id', 'fecha'], 'idx_asist_ie_fecha');
            $table->index(['usuario_app_id', 'fecha'], 'idx_asist_usuario_fecha');
            $table->index(['institucion_id', 'fecha', 'estado_diario'], 'idx_asist_ie_fecha_estado');
            $table->index(['horario_institucion_id', 'fecha'], 'idx_asist_horario_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
    }
};
