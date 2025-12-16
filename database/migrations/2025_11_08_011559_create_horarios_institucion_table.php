<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horarios_institucion', function (Blueprint $table) {
            $table->id();

            $table->foreignId('institucion_id')
                  ->constrained('instituciones')
                  ->onDelete('cascade');

            $table->string('nombre_turno', 50); // mañana / tarde / noche
            $table->time('hora_entrada');
            $table->time('hora_salida');
            $table->unsignedTinyInteger('tolerancia_minutos')->default(5);
            
            // Días laborales (L,M,X,J,V,S,D)
            $table->json('dias_semana')->nullable();

            $table->boolean('activo')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horarios_institucion');
    }
};