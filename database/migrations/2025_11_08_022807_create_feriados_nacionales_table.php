<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feriados', function (Blueprint $table) {
            $table->id();

            // Tipo de feriado
            $table->enum('tipo', ['nacional', 'institucional']);

            // Para institucionales
            $table->foreignId('institucion_id')->nullable()
                ->constrained('instituciones')
                ->onDelete('cascade');

            // Fecha fija o fecha específica
            // Si usas una fecha exacta:
            $table->date('fecha')->nullable();

            // Si usas feriados que se repiten cada año (formato dia/mes)
            $table->unsignedTinyInteger('dia')->nullable();
            $table->unsignedTinyInteger('mes')->nullable();

            $table->string('descripcion')->nullable();
            $table->boolean('activo')->default(true);

            $table->timestamps();

            // Evita duplicados
            $table->unique(['tipo', 'institucion_id', 'dia', 'mes'], 'feriado_dia_mes_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feriados');
    }
};
