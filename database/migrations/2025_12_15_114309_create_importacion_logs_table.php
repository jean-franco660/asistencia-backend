<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importaciones_log', function (Blueprint $table) {
            $table->id();

            // 🔧 CORRECCIÓN: Agregar onDelete para manejar soft deletes
            $table->foreignId('usuario_id')
                ->nullable()
                ->constrained('usuarios_web')
                ->nullOnDelete(); // Si se elimina el usuario, mantener el log

            $table->enum('tipo', ['instituciones', 'usuarios_app', 'asignaciones', 'asistencias'])->index();

            $table->string('archivo_original');
            $table->string('archivo_temp', 500);

            $table->enum('estado', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending')
                ->index();

            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('procesados')->default(0);
            $table->unsignedInteger('exitosos')->default(0);
            $table->unsignedInteger('errores_count')->default(0);

            $table->json('errores_detalle')->nullable();
            $table->string('errores_archivo', 500)->nullable();

            $table->timestamp('iniciado_en')->nullable();
            $table->timestamp('completado_en')->nullable();

            $table->timestamps();

            $table->index(['usuario_id', 'tipo']);
            $table->index(['estado', 'created_at']);
            $table->index(['usuario_id', 'created_at'], 'idx_import_usuario_fecha');
            $table->index(['usuario_id', 'estado'], 'idx_import_usuario_estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importaciones_log');
    }
};