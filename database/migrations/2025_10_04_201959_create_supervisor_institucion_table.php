<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

    Schema::create('supervisor_institucion', function (Blueprint $table) {
        $table->id();

        // 🔗 Relación: usuario web con rol supervisor/administrador
        $table->foreignId('usuario_web_id')
            ->constrained('usuarios_web')
            ->onDelete('cascade');
        
        // 🔗 Relación: institución
        $table->foreignId('institucion_id')
            ->constrained('instituciones')
            ->onDelete('cascade');

        // 📅 Auditoría opcional
        $table->date('fecha_inicio')->nullable();
        $table->date('fecha_fin')->nullable();

        $table->timestamps();

        // Evita duplicidad de asignación
        $table->unique(['usuario_web_id', 'institucion_id'], 'supervisor_ie_unique');
    });

    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_institucion');
    }

};
