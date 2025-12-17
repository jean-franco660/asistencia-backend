<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ===================================================
        // 1. JUSTIFICACIONES
        // ===================================================
        
        Schema::table('justificaciones', function (Blueprint $table) {
            // Verificar si ya existe la foreign key antes de modificarla
            try {
                // Eliminar constraint existente sin onDelete
                $table->dropForeign(['usuario_web_id']);
            } catch (\Exception $e) {
                // Si no existe, continuar
            }

            // Recrear con nullOnDelete
            $table->foreign('usuario_web_id')
                  ->references('id')
                  ->on('usuarios_web')
                  ->nullOnDelete();
        });

        // ===================================================
        // 2. IMPORTACIONES_LOG
        // ===================================================
        
        Schema::table('importaciones_log', function (Blueprint $table) {
            try {
                $table->dropForeign(['usuario_id']);
            } catch (\Exception $e) {
                // Si no existe, continuar
            }

            $table->foreign('usuario_id')
                  ->references('id')
                  ->on('usuarios_web')
                  ->nullOnDelete();
        });

        // ===================================================
        // 3. ÍNDICES ADICIONALES EN ASISTENCIAS
        // ===================================================
        
        Schema::table('asistencias', function (Blueprint $table) {
            // Índices para consultas frecuentes por fecha
            if (!$this->hasIndex('asistencias', 'idx_institucion_fecha')) {
                $table->index(['institucion_id', 'fecha'], 'idx_institucion_fecha');
            }
            
            if (!$this->hasIndex('asistencias', 'idx_usuario_fecha')) {
                $table->index(['usuario_app_id', 'fecha'], 'idx_usuario_fecha');
            }
            
            if (!$this->hasIndex('asistencias', 'idx_fecha_tipo')) {
                $table->index(['fecha', 'tipo'], 'idx_fecha_tipo');
            }

            // Índice para asistencias no sincronizadas
            if (!$this->hasIndex('asistencias', 'idx_sincronizado')) {
                $table->index('sincronizado', 'idx_sincronizado');
            }
        });

        // ===================================================
        // 4. ÍNDICES ADICIONALES EN FERIADOS
        // ===================================================
        
        Schema::table('feriados', function (Blueprint $table) {
            if (!$this->hasIndex('feriados', 'idx_institucion_fecha')) {
                $table->index(['institucion_id', 'fecha'], 'idx_institucion_fecha');
            }
            
            if (!$this->hasIndex('feriados', 'idx_tipo_fecha_activo')) {
                $table->index(['tipo', 'fecha', 'activo'], 'idx_tipo_fecha_activo');
            }
        });

        // ===================================================
        // 5. ÍNDICES ADICIONALES EN JUSTIFICACIONES
        // ===================================================
        
        Schema::table('justificaciones', function (Blueprint $table) {
            if (!$this->hasIndex('justificaciones', 'idx_estado_fecha')) {
                $table->index(['estado', 'fecha_inicio'], 'idx_estado_fecha');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revertir justificaciones
        Schema::table('justificaciones', function (Blueprint $table) {
            try {
                $table->dropForeign(['usuario_web_id']);
                $table->foreign('usuario_web_id')
                      ->references('id')
                      ->on('usuarios_web');
            } catch (\Exception $e) {
                // Ignorar si falla
            }
        });

        // Revertir importaciones_log
        Schema::table('importaciones_log', function (Blueprint $table) {
            try {
                $table->dropForeign(['usuario_id']);
                $table->foreign('usuario_id')
                      ->references('id')
                      ->on('usuarios_web');
            } catch (\Exception $e) {
                // Ignorar si falla
            }
        });

        // Eliminar índices de asistencias
        Schema::table('asistencias', function (Blueprint $table) {
            $indices = [
                'idx_institucion_fecha',
                'idx_usuario_fecha',
                'idx_fecha_tipo',
                'idx_sincronizado',
            ];

            foreach ($indices as $index) {
                if ($this->hasIndex('asistencias', $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        // Eliminar índices de feriados
        Schema::table('feriados', function (Blueprint $table) {
            $indices = [
                'idx_institucion_fecha',
                'idx_tipo_fecha_activo',
            ];

            foreach ($indices as $index) {
                if ($this->hasIndex('feriados', $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        // Eliminar índices de justificaciones
        Schema::table('justificaciones', function (Blueprint $table) {
            if ($this->hasIndex('justificaciones', 'idx_estado_fecha')) {
                $table->dropIndex('idx_estado_fecha');
            }
        });
    }

    /**
     * Helper para verificar si existe un índice
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            $indexes = Schema::getConnection()
                ->getDoctrineSchemaManager()
                ->listTableIndexes($table);

            return array_key_exists($indexName, $indexes);
        } catch (\Exception $e) {
            return false;
        }
    }
};