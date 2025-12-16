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
            
            // ¿Quién realizó la acción?
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type')->nullable(); // UsuarioWeb o UsuarioApp
            $table->string('actor_nombre')->nullable(); // Guardar nombre por si se elimina el usuario
            $table->string('actor_rol')->nullable(); // super_admin, administrador, supervisor
            
            // ¿Qué acción se realizó?
            $table->string('accion'); // created, updated, deleted, autorizado, rechazado, importado
            $table->string('descripcion')->nullable(); // Descripción legible de la acción
            
            // ¿Sobre qué entidad?
            $table->string('modelo'); // UsuarioWeb, UsuarioApp, Institucion, etc.
            $table->unsignedBigInteger('modelo_id')->nullable();
            $table->string('modelo_nombre')->nullable(); // Nombre del registro afectado
            
            // Datos de la acción
            $table->json('datos_anteriores')->nullable(); // Estado antes del cambio
            $table->json('datos_nuevos')->nullable(); // Estado después del cambio
            $table->json('metadata')->nullable(); // Info adicional (IPs, archivos importados, etc.)
            
            // Contexto de la petición
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('url')->nullable();
            $table->string('metodo_http')->nullable(); // GET, POST, PUT, DELETE
            
            $table->timestamps();
            
            // Índices para búsquedas rápidas
            $table->index(['actor_id', 'actor_type']);
            $table->index(['modelo', 'modelo_id']);
            $table->index('accion');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};