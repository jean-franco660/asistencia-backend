<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docente_institucion', function (Blueprint $table) {
            $table->id();

            // 🔹 Relaciones
            $table->foreignId('usuario_app_id')
                  ->constrained('usuarios_app')
                  ->onDelete('cascade')
                  ->comment('Docente relacionado');

            $table->foreignId('institucion_id')
                  ->constrained('instituciones')
                  ->onDelete('cascade')
                  ->comment('Institución asociada');

            $table->timestamps();

            $table->unique(['usuario_app_id', 'institucion_id'], 'usuario_institucion_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docente_institucion');
    }
};
