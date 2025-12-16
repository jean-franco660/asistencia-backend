<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuarios_app', function (Blueprint $table) {
            $table->id(); // PK: BIGINT UNSIGNED AUTO_INCREMENT
            
            // 🔹 CÓDIGO MODULAR DEL DOCENTE (puede cambiar, SOLO para login)
            $table->string('codigo_modular_docente', 20)
                  ->unique();

            // 🔹 FK: Relación con institución usando ID
            $table->foreignId('institucion_id')
                  ->nullable()
                  ->constrained('instituciones')
                  ->onUpdate('cascade')
                  ->onDelete('restrict');

            // 🔹 DATOS PERSONALES
            $table->string('apellido_paterno', 100);

            $table->string('apellido_materno', 100);

            $table->string('nombres', 100);

            $table->char('sexo', 1);

            // 🔹 DATOS LABORALES
            $table->enum('estado', ['ACTIVO', 'INACTIVO'])
                  ->default('ACTIVO');

            $table->string('cargo', 50);

            // 🔹 CREDENCIALES DE ACCESO
            $table->string('password');
            
            $table->boolean('activo')
                  ->default(true);

            $table->timestamps();
            
            // 🔹 ÍNDICES
            $table->index('institucion_id');
            $table->index('estado');
            $table->index(['cargo', 'estado']);
            $table->index('apellido_paterno');
            $table->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios_app');
    }
};