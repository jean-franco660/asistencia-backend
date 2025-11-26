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

            // Relaciones
            $table->foreignId('usuario_id')
                ->constrained('usuarios_app')
                ->cascadeOnDelete();

            $table->foreignId('institucion_id')
                ->constrained('instituciones')
                ->cascadeOnDelete();

            // Fecha y hora
            $table->dateTime('fecha_hora')->index(); // Fecha y hora de registro de la asistencia

            // Ubicación
            $table->boolean('dentro_rango')->default(false);
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();

            // Evidencia
            $table->string('foto')->nullable();

            // Tipo y turno
            $table->enum('tipo', ['entrada', 'salida'])->nullable();
            $table->string('turno', 20)->nullable();

            $table->string('estado', 20)->nullable(); // a_tiempo, tarde, salida_antes

            // Faltas
            $table->boolean('falta')->default(false);

            // Sincronización
            $table->boolean('sincronizado')->default(false);

            // Evitar duplicados
            $table->unique(['usuario_id', 'fecha_hora', 'tipo'], 'usuario_fecha_tipo_unique');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
    }
};
