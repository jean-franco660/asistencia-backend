<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuario_app_institucion', function (Blueprint $table) {
            $table->id();

            $table->foreignId('usuario_app_id')
                ->constrained('usuarios_app')
                ->cascadeOnDelete();

            $table->foreignId('institucion_id')
                ->constrained('instituciones')
                ->restrictOnDelete();

            $table->foreignId('horario_institucion_id')
                ->nullable()
                ->constrained('horarios_institucion')
                ->restrictOnDelete();

            $table->string('cargo', 50)->nullable();

            // 🎯 Se activa automáticamente al asignar horario (via Observer)
            $table->enum('estado', ['PENDIENTE', 'ACTIVO', 'INACTIVO'])
                ->default('PENDIENTE')
                ->comment('ACTIVO automático al asignar horario');

            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['usuario_app_id', 'institucion_id'], 'uq_usuario_app_institucion');
            $table->index(['institucion_id', 'estado'], 'idx_ie_estado');
            $table->index(['usuario_app_id', 'estado'], 'idx_usuario_estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_app_institucion');
    }
};