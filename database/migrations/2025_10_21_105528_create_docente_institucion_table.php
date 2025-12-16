<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('docente_institucion', function (Blueprint $table) {
            $table->id();

            $table->foreignId('usuario_app_id')
                ->constrained('usuarios_app')
                ->onDelete('cascade');

            $table->foreignId('institucion_id')
                ->constrained('instituciones')
                ->onDelete('cascade');

            $table->enum('estado', ['ACTIVO', 'INACTIVO'])->default('ACTIVO');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();

            $table->timestamps();

            $table->unique(['usuario_app_id', 'institucion_id'], 'uk_docente_institucion');

            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docente_institucion');
    }
};
