<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla `sessions` utilizada por el driver de sesiones basado en base de datos.
 * Almacena la sesión HTTP de cada usuario junto con su IP, agente y última actividad.
 */
return new class extends Migration
{
    /**
     * Crea la tabla de sesiones.
     */
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Elimina la tabla de sesiones.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
