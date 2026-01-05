<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('horarios_cambios_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_app_id')->constrained('usuarios_app');
            $table->foreignId('institucion_id')->constrained('instituciones');
            $table->json('horario_anterior')->nullable();
            $table->json('horario_nuevo');
            $table->enum('origen', ['APP', 'ADMIN'])->default('APP');
            $table->foreignId('usuario_admin_id')->nullable()->constrained('usuarios_web');
            $table->text('motivo')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('horarios_cambios_log');
    }
};
