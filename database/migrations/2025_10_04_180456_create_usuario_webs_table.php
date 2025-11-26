<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Ejecuta la migración.
     */
    public function up(): void
    {
        Schema::create('usuarios_web', function (Blueprint $table) {
            $table->id(); // Identificador único
            $table->string('nombre'); // Nombre completo
            $table->string('email')->unique(); // Correo de acceso
            $table->string('password'); // Contraseña cifrada
            $table->enum('rol', ['admin', 'director'])->default('director'); // Rol del usuario
            
            // Solo aplica a directores, pero se guarda aquí
            $table->enum('estado', ['pendiente', 'autorizado', 'rechazado'])->default('pendiente');
            
            $table->timestamps(); // created_at y updated_at
        });
    }

    /**
     * Revierte la migración.
     */
    public function down(): void
    {
        Schema::dropIfExists('usuarios_web');
    }
};
