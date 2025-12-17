<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencias', function (Blueprint $table) {
            $table->id();

            $table->foreignId('usuario_app_id')
                ->constrained('usuarios_app')
                ->cascadeOnDelete();

            $table->foreignId('institucion_id')
                ->constrained('instituciones')
                ->cascadeOnDelete();

            // Turno real (horario asignado)
            $table->foreignId('horario_institucion_id')
                ->constrained('horarios_institucion')
                ->restrictOnDelete();

            // Fecha y hora
            $table->date('fecha');
            $table->dateTime('fecha_hora');

            // Entrada / salida
            $table->enum('tipo', ['ENTRADA', 'SALIDA']);

            // Resultado de marcación
            $table->enum('resultado', ['A_TIEMPO', 'TARDE', 'SALIDA_ANTES'])->nullable();

            // Situación administrativa
            $table->enum('situacion', ['NORMAL', 'FALTA', 'JUSTIFICADO'])->default('NORMAL');

            // Geolocalización
            $table->boolean('dentro_rango')->default(false);
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();

            // Evidencia
            $table->string('foto')->nullable();

            // Sync offline
            $table->boolean('sincronizado')->default(false);

            $table->timestamps();

            $table->unique(
                ['usuario_app_id', 'institucion_id', 'horario_institucion_id', 'fecha', 'tipo'],
                'uk_asistencia_diaria'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
    }
};