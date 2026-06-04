<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla `usuarios_app`, que representa a los docentes y personal que registran
 * asistencia mediante la aplicación móvil. Autentican con código modular (identificador UGEL)
 * en lugar de email. El soft delete conserva el historial ante bajas administrativas.
 */
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
            $table->char('sexo', 1)->nullable(); // 'M' = Masculino, 'F' = Femenino

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
