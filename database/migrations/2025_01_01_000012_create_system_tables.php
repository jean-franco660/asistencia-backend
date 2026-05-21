<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // =============================================
        // PERSONAL ACCESS TOKENS (Sanctum)
        // =============================================
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        // =============================================
        // JOBS (Cola de trabajos — importaciones, etc.)
        // =============================================
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        // =============================================
        // FAILED JOBS
        // =============================================
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        // =============================================
        // IMPORTACIONES LOG
        // =============================================
        Schema::create('importaciones_log', function (Blueprint $table) {
            $table->id();

            $table->foreignId('usuario_id')
                ->nullable()
                ->constrained('usuarios_web')
                ->nullOnDelete();

            $table->enum('tipo', ['instituciones', 'usuarios_app', 'asignaciones', 'asistencias'])->index();

            $table->string('archivo_original');
            $table->string('archivo_temp', 500);

            $table->enum('estado', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending')
                ->index();

            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('procesados')->default(0);
            $table->unsignedInteger('exitosos')->default(0);
            $table->unsignedInteger('errores_count')->default(0);

            $table->json('errores_detalle')->nullable();
            $table->string('errores_archivo', 500)->nullable();

            $table->timestamp('iniciado_en')->nullable();
            $table->timestamp('completado_en')->nullable();

            $table->timestamps();

            $table->index(['usuario_id', 'tipo']);
            $table->index(['estado', 'created_at']);
            $table->index(['usuario_id', 'created_at'], 'idx_import_usuario_fecha');
            $table->index(['usuario_id', 'estado'], 'idx_import_usuario_estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importaciones_log');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('personal_access_tokens');
    }
};
