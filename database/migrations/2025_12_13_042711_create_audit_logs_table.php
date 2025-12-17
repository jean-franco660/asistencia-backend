<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Actor
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type', 30)->nullable(); // USUARIO_WEB / USUARIO_APP
            $table->string('actor_nombre')->nullable();
            $table->string('actor_rol', 30)->nullable();

            // Acción
            $table->string('accion', 50);
            $table->string('descripcion')->nullable();

            // Entidad afectada
            $table->string('modelo', 50);
            $table->unsignedBigInteger('modelo_id')->nullable();
            $table->string('modelo_nombre')->nullable();

            // Datos
            $table->json('datos_anteriores')->nullable();
            $table->json('datos_nuevos')->nullable();
            $table->json('metadata')->nullable();

            // Contexto HTTP
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('url')->nullable();
            $table->string('metodo_http', 10)->nullable();

            $table->timestamps();

            // Índices
            $table->index(['actor_id','actor_type']);
            $table->index(['modelo','modelo_id']);
            $table->index('accion');
            $table->index('created_at');
            $table->index(['accion','created_at'], 'idx_audit_accion_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};