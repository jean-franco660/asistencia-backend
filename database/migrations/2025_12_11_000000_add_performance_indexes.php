<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Índices para optimización de performance
        Schema::table('asistencias', function (Blueprint $table) {
            // Índice compuesto para consultas por fecha e institución
            $table->index(['fecha_hora', 'institucion_id'], 'idx_asistencias_fecha_inst');

            // Índice para consultas de usuario
            $table->index(['usuario_app_id', 'fecha_hora'], 'idx_asistencias_usuario_fecha');

            // Índice para tipo de asistencia
            $table->index('tipo', 'idx_asistencias_tipo');
        });

        Schema::table('docente_institucion', function (Blueprint $table) {
            // Índice para filtrado por estado y fechas
            $table->index(['institucion_id', 'estado'], 'idx_docente_inst_estado');
            $table->index(['usuario_app_id', 'estado'], 'idx_docente_usuario_estado');
        });

        Schema::table('usuarios_app', function (Blueprint $table) {
            // Índice para búsquedas por nombre
            $table->index('apellido_paterno', 'idx_usuarios_app_apellido');

            // Índice para estado y activo
            $table->index(['estado', 'activo'], 'idx_usuarios_app_estado_activo');
        });

        Schema::table('horarios_institucion', function (Blueprint $table) {
            // Índice para consultas de horarios activos por institución
            $table->index(['institucion_id', 'activo'], 'idx_horarios_inst_activo');
        });
    }

    public function down(): void
    {
        // Usar dropIndex con try-catch para evitar errores si no existen
        $this->safeDropIndex('asistencias', 'idx_asistencias_fecha_inst');
        $this->safeDropIndex('asistencias', 'idx_asistencias_usuario_fecha');
        $this->safeDropIndex('asistencias', 'idx_asistencias_tipo');

        $this->safeDropIndex('docente_institucion', 'idx_docente_inst_estado');
        $this->safeDropIndex('docente_institucion', 'idx_docente_usuario_estado');

        $this->safeDropIndex('usuarios_app', 'idx_usuarios_app_apellido');
        $this->safeDropIndex('usuarios_app', 'idx_usuarios_app_estado_activo');

        $this->safeDropIndex('horarios_institucion', 'idx_horarios_inst_activo');
    }

    private function safeDropIndex(string $table, string $indexName): void
    {
        try {
            $exists = DB::selectOne(
                "SELECT 1
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                AND table_name = ?
                AND index_name = ?
                LIMIT 1",
                [$table, $indexName]
            );

            if ($exists) {
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
            }
        } catch (\Exception $e) {
            // Ignorar errores si el índice no existe
        }
    }
};