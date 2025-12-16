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
            $table->foreignId('usuario_app_id')
                  ->constrained('usuarios_app')
                  ->onDelete('cascade');

            $table->foreignId('institucion_id')
                  ->constrained('instituciones')
                  ->onDelete('cascade');

            // Datos de la asistencia
            $table->dateTime('fecha_hora');
            $table->enum('tipo', ['entrada', 'salida'])->nullable();
            $table->enum('turno', ['MAÑANA', 'TARDE', 'NOCHE'])->nullable();
            $table->enum('estado', ['a_tiempo', 'tarde', 'salida_antes', 'falta', 'justificado'])->nullable();

            // Geolocalización
            $table->boolean('dentro_rango')->default(false);
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();

            // Evidencia fotográfica
            $table->string('foto', 255)->nullable();

            // Control de faltas
            $table->boolean('falta')->default(false);
            $table->boolean('falta_entrada')->default(false);
            $table->boolean('falta_salida')->default(false);

            // Sincronización (para app offline)
            $table->boolean('sincronizado')->default(false);

            $table->timestamps();

            // Índices para optimización
            $table->index(['usuario_app_id', 'fecha_hora']);
            $table->index(['institucion_id', 'fecha_hora']);
            $table->index('estado');
            $table->index('tipo');
            $table->index('turno');
            $table->index('falta');
            $table->index('fecha_hora');

            // Constraint único: un usuario no puede tener dos registros del mismo tipo en la misma fecha/hora
            $table->unique(
                ['usuario_app_id', 'fecha_hora', 'tipo'],
                'uk_asistencia_usuario_fecha_tipo'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
    }
};