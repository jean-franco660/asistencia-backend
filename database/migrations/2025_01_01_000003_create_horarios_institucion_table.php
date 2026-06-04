<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla `horarios_institucion`, que define los turnos laborales (mañana, tarde, noche)
 * de cada institución, con horas de entrada/salida, tolerancias en minutos y los días
 * activos de la semana. La restricción de unicidad evita turnos duplicados por institución.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('horarios_institucion', function (Blueprint $table) {
            $table->id();

            $table->foreignId('institucion_id')
                ->constrained('instituciones')
                ->cascadeOnDelete();

            $table->enum('nombre_turno', ['MAÑANA', 'TARDE', 'NOCHE']);
            $table->time('hora_entrada');
            $table->time('hora_salida');
            $table->unsignedTinyInteger('tolerancia_entrada_minutos')->default(5);
            $table->unsignedTinyInteger('tolerancia_salida_minutos')->default(5);

            // Días laborales: ["L","M","X","J","V"]
            $table->json('dias_semana')->nullable();

            $table->boolean('activo')->default(true);

            $table->timestamps();

            // Evita duplicados de turnos por IE
            $table->unique(['institucion_id', 'nombre_turno'], 'uk_ie_turno');

            // Consulta frecuente: horarios activos de una institución
            $table->index(['institucion_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horarios_institucion');
    }
};
