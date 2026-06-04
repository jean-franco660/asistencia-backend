<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla `failed_jobs` para almacenar los trabajos de cola que fallaron.
 * Permite inspeccionar el error, la conexión y el payload para diagnóstico o reintento.
 */
return new class extends Migration
{
    /**
     * Crea la tabla de jobs fallidos.
     */
    public function up(): void
    {
        if (!Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }
    /**
     * Elimina la tabla de jobs fallidos.
     */
    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
    }
};
