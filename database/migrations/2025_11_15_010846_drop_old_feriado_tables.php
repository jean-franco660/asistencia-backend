<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Borra las tablas si existen
        Schema::dropIfExists('feriados_nacionales');
        Schema::dropIfExists('feriados_institucionales');
    }

    public function down(): void
    {
        // Si quieres permitir revertir, puedes volver a crearlas
        Schema::create('feriados_nacionales', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->string('nombre');
            $table->timestamps();
        });

        Schema::create('feriados_institucionales', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->string('nombre');
            $table->unsignedBigInteger('institucion_id');
            $table->timestamps();
        });
    }
};
