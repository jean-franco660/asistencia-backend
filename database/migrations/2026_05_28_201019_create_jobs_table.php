<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla `jobs` utilizada por el driver de colas basado en base de datos.
 * La verificación previa con hasTable evita conflictos si la tabla fue creada por otra migración.
 */
return new class extends Migration
{
    /**
     * Crea la tabla de jobs.
     */
    public function up(): void
    {
        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
    }
    /**
     * Elimina la tabla de jobs.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
