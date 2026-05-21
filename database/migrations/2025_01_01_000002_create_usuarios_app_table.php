<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuarios_app', function (Blueprint $table) {
            $table->id();

            // Identificador oficial UGEL para login
            $table->string('codigo_modular', 20)->unique();

            // Documento de identidad
            $table->string('dni', 15)->unique();

            // Identidad
            $table->string('apellido_paterno', 100);
            $table->string('apellido_materno', 100)->nullable();
            $table->string('nombres', 150);
            $table->char('sexo', 1)->nullable();

            // Contacto
            $table->string('telefono', 20)->nullable()->index();

            // Control de acceso (único campo para habilitar/deshabilitar)
            $table->boolean('acceso_habilitado')->default(true)->index();

            // Credenciales
            $table->string('password');
            $table->rememberToken();

            $table->timestamps();
            $table->softDeletes();

            // Índices para búsquedas frecuentes
            $table->index(['apellido_paterno', 'apellido_materno', 'nombres'], 'idx_usuarios_app_nombre');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios_app');
    }
};
