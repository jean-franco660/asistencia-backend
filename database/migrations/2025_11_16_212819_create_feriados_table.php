<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('feriados', function (Blueprint $table) {
            $table->id();

            // Día y mes (rápido para consultas)
            $table->unsignedTinyInteger('dia');
            $table->unsignedTinyInteger('mes'); 

            // Fecha completa (para reportes e históricos)
            $table->date('fecha');

            // Nacional | Institucional
            $table->enum('tipo', ['nacional', 'institucional']);

            // Solo si es institucional
            $table->unsignedBigInteger('institucion_id')->nullable();

            // Activo / Inactivo
            $table->boolean('activo')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feriados');
    }

};
