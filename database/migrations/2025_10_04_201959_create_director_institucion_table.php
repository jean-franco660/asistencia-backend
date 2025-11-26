<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('director_institucion', function (Blueprint $table) {
            $table->id();

            // 🔗 Relaciones con claves foráneas
            $table->foreignId('usuario_web_id')
                  ->constrained('usuarios_web')
                  ->onDelete('cascade');
            
            $table->foreignId('institucion_id')
                  ->constrained('instituciones')
                  ->onDelete('cascade');

            // 📅 Campos adicionales opcionales (útiles para auditoría)
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();

            $table->timestamps();

            // 🔒 Evita duplicidad (un mismo director no se repite en la misma institución)
            $table->unique(['usuario_web_id', 'institucion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('director_institucion');
    }
};
