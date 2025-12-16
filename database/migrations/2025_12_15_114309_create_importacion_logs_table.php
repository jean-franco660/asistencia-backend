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
            
            // Usuario que inició la importación
            $table->foreignId('usuario_id')
                ->nullable()
                ->constrained('usuarios_web')
                ->nullOnDelete();
            
            // Tipo de importación
            $table->enum('tipo', ['instituciones', 'docentes', 'asistencias'])
                ->index();
            
            // Información del archivo
            $table->string('archivo_original');
            $table->string('archivo_temp');
            
            // Estado de la importación
            $table->enum('estado', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending')
                ->index();
            
            // Estadísticas
            $table->integer('total')->default(0);
            $table->integer('procesados')->default(0);
            $table->integer('exitosos')->default(0);
            $table->integer('errores_count')->default(0);
            $table->integer('porcentaje')->default(0);
            
            // Errores detallados (JSON)
            $table->json('errores_detalle')->nullable();
            
            // Timestamps de proceso
            $table->timestamp('iniciado_en')->nullable();
            $table->timestamp('completado_en')->nullable();
            
            $table->timestamps();
            
            // Índices para consultas frecuentes
            $table->index(['usuario_id', 'tipo']);
            $table->index(['estado', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importaciones_log');
    }
};