<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ejecuta la migración.
     */
    public function up(): void
    {
        Schema::create('instituciones', function (Blueprint $table) {
            $table->id();
            
            // Código modular de la institución
            $table->string('codigo_modular_ie', 20)->unique();

            // Datos principales
            $table->string('nombre', 255);
            $table->string('nivel_educativo', 100)->nullable();

            // Datos de ubicación
            $table->string('distrito', 100);
            $table->string('centro_poblado', 150)->nullable();
            $table->string('direccion', 255)->nullable();

            // Georreferenciación para validación de asistencia
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->integer('radio')->default(30); // metros

            // Logo de la institución
            $table->string('logo')->nullable();

            // Timestamps Laravel
            $table->timestamps();
            
            // Índices adicionales
            $table->index('distrito');
            $table->index('nivel_educativo');
        });
    }

    /**
     * Revierte la migración.
     */
    public function down(): void
    {
        Schema::dropIfExists('instituciones');
    }
};