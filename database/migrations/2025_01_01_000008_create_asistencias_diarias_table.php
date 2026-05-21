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
                ->cascadeOnDelete();

            // Tipo de marcación: ENTRADA / SALIDA
            $table->string('tipo', 20)->index();

            // Momento exacto de la marcación
            $table->timestamp('marcada_en')->index();

            // Geolocalización capturada por el dispositivo
            $table->decimal('latitud', 11, 7)->nullable();
            $table->decimal('longitud', 11, 7)->nullable();

            // Validación geográfica
            $table->unsignedInteger('distancia_m')->nullable();
            $table->boolean('dentro_rango')->default(false)->index();

            // Estado de la marcación: VALIDA / OBSERVADA / ANULADA
            $table->string('estado_marcacion', 20)->default('VALIDA')->index();
            $table->string('motivo', 255)->nullable();
            $table->text('observacion')->nullable();

            // Evidencia
            $table->string('foto_url', 500)->nullable();

            // Soporte offline/sincronización
            $table->uuid('offline_uuid')->nullable()->unique();
            $table->string('registrado_en', 30)->default('APP_ONLINE')->index();
            $table->timestamp('synced_at')->nullable()->index();

            // Metadata flexible
            $table->json('meta')->nullable();

            // --- Revisión humana (consolidado) ---
            $table->string('estado_revision', 20)->default('PENDIENTE')->index();
            $table->foreignId('revisado_por_usuario_web_id')
                ->nullable()
                ->constrained('usuarios_web');
            $table->timestamp('revisado_en')->nullable();
            $table->text('revision_observacion')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Ordenar marcaciones por tiempo dentro de una asistencia
            $table->index(['asistencia_id', 'marcada_en'], 'idx_marc_asistencia_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias_diarias');
    }
};
