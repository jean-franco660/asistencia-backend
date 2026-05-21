<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('feriados', function (Blueprint $table) {
            $table->id();

            // Tipo: Nacional o Institucional
            $table->enum('tipo', ['nacional', 'institucional']);

            // Solo si es institucional
            $table->foreignId('institucion_id')
                ->nullable()
                ->constrained('instituciones')
                ->cascadeOnDelete();

            // Día y mes (rápido para consultas recurrentes)
            $table->unsignedTinyInteger('dia');
            $table->unsignedTinyInteger('mes');

            // Descripción del feriado
            $table->string('descripcion');

            // Fecha completa (para reportes e históricos)
            $table->date('fecha');

            $table->boolean('activo')->default(true);

            $table->timestamps();

            $table->index(['tipo', 'dia', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feriados');
    }
};
