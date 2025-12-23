<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // ASISTENCIAS
        // ASISTENCIAS - Indices moved to base table for clean setup
        Schema::table('asistencias', function (Blueprint $table) {
            // idx_asistencias_usuario_fecha -> Already in base
            // idx_asistencias_inst_fecha -> Base has idx_asistencias_institucion_fecha

            // Validaciones por horario (Adding this one as it matches the new column)
            // Check if base has it? Base does NOT have index on horario.
            $table->index(
                ['horario_institucion_id', 'fecha'],
                'idx_asistencias_horario_fecha'
            );

            // tipo and situacion removed from base table
            // $table->index('tipo', 'idx_asistencias_tipo');
            // $table->index('situacion', 'idx_asistencias_situacion');
        });

        // ASIGNACIONES USUARIO - INSTITUCIÓN
        Schema::table('usuario_app_institucion', function (Blueprint $table) {
            $table->index(
                ['institucion_id', 'estado'],
                'idx_usuario_inst_estado'
            );

            // idx_usuario_estado ya existe en la migración original
            // $table->index(['usuario_app_id', 'estado'], 'idx_usuario_estado');

            $table->index(
                ['horario_institucion_id', 'estado'],
                'idx_usuario_horario_estado'
            );
        });

        // USUARIOS APP
        Schema::table('usuarios_app', function (Blueprint $table) {
            $table->index(
                'apellido_paterno',
                'idx_usuarios_app_apellido'
            );

            $table->index(
                'acceso_habilitado',
                'idx_usuarios_app_acceso'
            );
        });

        // HORARIOS
        Schema::table('horarios_institucion', function (Blueprint $table) {
            $table->index(
                ['institucion_id', 'activo'],
                'idx_horarios_inst_activo'
            );
        });
    }

    public function down(): void
    {
        $this->safeDropIndex('asistencias', 'idx_asistencias_inst_fecha');
        $this->safeDropIndex('asistencias', 'idx_asistencias_usuario_fecha');
        $this->safeDropIndex('asistencias', 'idx_asistencias_horario_fecha');
        $this->safeDropIndex('asistencias', 'idx_asistencias_tipo');
        $this->safeDropIndex('asistencias', 'idx_asistencias_situacion');

        $this->safeDropIndex('usuario_app_institucion', 'idx_usuario_inst_estado');
        // idx_usuario_estado ya existe en la migración original, no se elimina aquí
        // $this->safeDropIndex('usuario_app_institucion', 'idx_usuario_estado');
        $this->safeDropIndex('usuario_app_institucion', 'idx_usuario_horario_estado');

        $this->safeDropIndex('usuarios_app', 'idx_usuarios_app_apellido');
        $this->safeDropIndex('usuarios_app', 'idx_usuarios_app_acceso');

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
            // Ignorar
        }
    }
};
