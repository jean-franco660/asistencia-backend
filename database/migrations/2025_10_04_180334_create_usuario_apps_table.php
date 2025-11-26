<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuarios_app', function (Blueprint $table) {
            $table->id();

            // 🔹 Datos principales
            $table->string('nombre')->comment('Nombre completo del docente');
            $table->string('codigo')->unique()->comment('Código de acceso para login');
            $table->string('password')->comment('Contraseña hasheada');

            // 🔹 Estado del usuario
            $table->boolean('activo')
                ->default(true)
                ->comment('Indica si el docente puede iniciar sesión');

            // 🔹 Timestamp
            $table->timestamps();

            // 🔹 Índices opcionales
            $table->index('activo'); // facilita búsquedas de docentes activos
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios_app');
    }
};
