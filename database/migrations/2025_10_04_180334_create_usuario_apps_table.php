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

            // Datos personales
            $table->string('apellido_paterno', 100);
            $table->string('apellido_materno', 100);
            $table->string('nombres', 100);
            $table->char('sexo', 1)->nullable()->comment('M=Masculino, F=Femenino');

            // Acceso general a la app
            $table->boolean('acceso_habilitado')->default(true);

            // Credenciales
            $table->string('password');
            $table->rememberToken();

            $table->timestamps();

            // Índices para consultas frecuentes
            $table->index('apellido_paterno', 'idx_apellido_paterno');
            $table->index('acceso_habilitado', 'idx_acceso');
            $table->index(['apellido_paterno', 'apellido_materno'], 'idx_apellidos');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios_app');
    }
};