<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instituciones', function (Blueprint $table) {
            $table->id();

            // Identificador único UGEL
            $table->string('codigo_modular_ie', 20)->unique();

            // Datos básicos
            $table->string('nombre', 255);
            // Clasificación
            $table->string('nivel_educativo', 50)->index(); // NIVEL_MOD
            $table->string('tipo_gestion', 50)->nullable()->index();
            // Ejemplos: PUBLICA, PRIVADA, PUBLICA_CONVENIO
            // Ubicación administrativa
            $table->string('distrito', 100)->index();

            // Geolocalización (precisión de 7 decimales = ~1.1cm)
            $table->decimal('latitud', 11, 7)->nullable();
            $table->decimal('longitud', 11, 7)->nullable();
            $table->unsignedInteger('radio')->default(30)->comment('Radio en metros');

            // Logo
            $table->string('logo', 500)->nullable();

            $table->timestamps();

            // Índices para búsquedas frecuentes
            $table->index('distrito', 'idx_distrito');
            $table->index('nivel_educativo', 'idx_nivel');
            $table->index(['distrito', 'nivel_educativo'], 'idx_distrito_nivel');
            $table->index('codigo_modular_ie', 'idx_codigo');
            $table->index('tipo_gestion', 'idx_gestion');
            
            // Índice espacial si usas MySQL 8+ (opcional para búsquedas por coordenadas)
            // $table->spatialIndex(['latitud', 'longitud'], 'idx_coordenadas');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instituciones');
    }
};