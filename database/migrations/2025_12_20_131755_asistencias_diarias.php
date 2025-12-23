<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('asistencias_diarias', function (Blueprint $table) {
            $table->id();

            // Relación con cabecera
            $table->foreignId('asistencia_id')
                ->constrained('asistencias')
                ->cascadeOnDelete(); // si borras la cabecera (lógico), se van sus marcaciones

            /**
             * Tipo de marcación
             * ENTRADA / SALIDA (o más adelante: INICIO_RECESO / FIN_RECESO, etc.)
             */
            $table->string('tipo', 20)->index();

            // Momento exacto (mejor que solo "hora" porque soporta auditoría)
            $table->timestamp('marcada_en')->index();

            // Geolocalización capturada por el dispositivo
            $table->decimal('latitud', 11, 7)->nullable();
            $table->decimal('longitud', 11, 7)->nullable();

            // Validación geográfica calculada por backend
            $table->unsignedInteger('distancia_m')->nullable();
            $table->boolean('dentro_rango')->default(false)->index();

            /**
             * Estado de la marcación
             * VALIDA / OBSERVADA / ANULADA
             */
            $table->string('estado_marcacion', 20)->default('VALIDA')->index();

            // Motivo/observación
            $table->string('motivo', 255)->nullable(); // ej: "FUERA_DE_RANGO", "SIN_GPS"
            $table->text('observacion')->nullable();

            // Evidencia
            $table->string('foto_url', 500)->nullable();

            /**
             * Soporte offline/sincronización
             * offline_uuid: id único generado por app para idempotencia (evitar duplicados)
             */
            $table->uuid('offline_uuid')->nullable()->unique();

            $table->string('registrado_en', 30)->default('APP_ONLINE')->index(); // APP_ONLINE/APP_OFFLINE/WEB
            $table->timestamp('synced_at')->nullable()->index();

            // Metadata flexible (precisión GPS, versión app, modelo, etc.)
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            /**
             * Restricción anti-duplicados típica:
             * una ENTRADA y una SALIDA por asistencia_id
             * (si luego permites múltiples turnos, se ajusta)
             */
            // $table->unique(['asistencia_id', 'tipo'], 'uq_asistencia_diarias_asistencia_tipo');

            // Índice útil para ordenar marcaciones por tiempo
            $table->index(['asistencia_id', 'marcada_en'], 'idx_marc_asistencia_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias_diarias');
    }
};
