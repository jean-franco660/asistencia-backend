<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuarios_web', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('email')->unique();
            $table->string('password');

            // Roles: super_admin, administrador, supervisor
            $table->enum('rol', ['super_admin', 'administrador', 'supervisor'])
                ->default('supervisor');

            // Estado del usuario en el sistema
            $table->enum('estado', ['pendiente', 'autorizado', 'rechazado'])
                ->default('pendiente');

            // Enlace opcional con usuario de la app (provisioning)
            $table->foreignId('usuario_app_id')
                ->nullable()
                ->unique('uk_usuarios_web_usuario_app')
                ->constrained('usuarios_app')
                ->nullOnDelete();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            // Índices para consultas frecuentes
            $table->index(['email', 'estado']);
            $table->index(['rol', 'estado']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios_web');
    }
};
