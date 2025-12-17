<?php

namespace App\Jobs;

use App\Imports\UsuariosAppImport;
use App\Models\ImportacionLog;
use App\Services\ImportUsuariosAppService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportarUsuariosAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200; // 2 horas
    public $tries = 3;
    public $backoff = 300; // 5 minutos entre reintentos
    public $maxExceptions = 3;

    protected int $importLogId;
    protected string $archivoPath;

    /**
     * @param int $importLogId - ID del registro ImportacionLog
     * @param string $archivoPath - Ruta relativa en storage (ej: "temp/import_docentes_123.xlsx")
     */
    public function __construct(int $importLogId, string $archivoPath)
    {
        $this->importLogId = $importLogId;
        $this->archivoPath = $archivoPath;
    }

    /**
     * Ejecuta el Job usando el Service y el Import
     */
    public function handle(ImportUsuariosAppService $service): void
    {
        $importLog = ImportacionLog::findOrFail($this->importLogId);

        try {
            Log::info("🚀 Iniciando ImportarDocentesJob", [
                'import_log_id' => $importLog->id,
                'archivo' => $this->archivoPath,
            ]);

            // Marcar como procesando
            $importLog->update([
                'estado' => 'processing',
                'iniciado_en' => now(),
            ]);

            // Verificar que el archivo existe
            if (!Storage::exists($this->archivoPath)) {
                throw new Exception("Archivo no encontrado en storage: {$this->archivoPath}");
            }

            $absolutePath = Storage::path($this->archivoPath);

            // Contar total de filas (sin encabezado)
            $data = Excel::toArray(new \stdClass(), $absolutePath);
            $totalFilas = 0;
            
            if (!empty($data) && is_array($data[0])) {
                $totalFilas = max(0, count($data[0]) - 1); // -1 por encabezado
            }

            $importLog->update(['total' => $totalFilas]);

            Log::info("📊 Total de docentes a procesar", [
                'import_log_id' => $importLog->id,
                'total' => $totalFilas,
            ]);

            // 🎯 AQUÍ ES DONDE USA TU SERVICE E IMPORT
            // El Import procesa chunks automáticamente y llama al Service
            // El Service usa cache de instituciones y maneja el pivot
            Excel::import(
                new UsuariosAppImport($importLog, $service),
                $absolutePath
            );

            // Marcar como completado
            $importLog->update([
                'estado' => 'completed',
                'completado_en' => now(),
            ]);

            Log::info("✅ ImportarDocentesJob completado", [
                'import_log_id' => $importLog->id,
                'total' => $importLog->total,
                'procesados' => $importLog->procesados,
                'exitosos' => $importLog->exitosos,
                'errores' => $importLog->errores_count,
                'duracion' => $importLog->iniciado_en->diffInSeconds($importLog->completado_en) . 's',
            ]);

            // Limpiar archivo temporal (opcional)
            if (Storage::exists($this->archivoPath)) {
                Storage::delete($this->archivoPath);
                Log::info("🗑️ Archivo temporal eliminado", [
                    'archivo' => $this->archivoPath,
                ]);
            }

        } catch (Exception $e) {
            Log::error("❌ Error en ImportarDocentesJob", [
                'import_log_id' => $importLog->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $importLog->update([
                'estado' => 'failed',
                'completado_en' => now(),
                'errores_detalle' => array_merge(
                    $importLog->errores_detalle ?? [],
                    [[
                        'fila' => 0,
                        'codigo_docente' => 'ERROR CRÍTICO',
                        'docente' => null,
                        'codigo_modular_ie' => null,
                        'motivo' => 'Error general del Job: ' . $e->getMessage(),
                        'timestamp' => now()->toISOString(),
                    ]]
                ),
            ]);

            throw $e;
        }
    }

    /**
     * Manejo cuando el Job falla definitivamente (después de todos los reintentos)
     */
    public function failed(Exception $exception): void
    {
        Log::error("💥 ImportarDocentesJob falló definitivamente", [
            'import_log_id' => $this->importLogId,
            'error' => $exception->getMessage(),
            'intentos' => $this->attempts(),
        ]);

        try {
            $importLog = ImportacionLog::find($this->importLogId);
            
            if ($importLog && $importLog->estado !== 'failed') {
                $importLog->update([
                    'estado' => 'failed',
                    'completado_en' => now(),
                ]);
            }

            // Limpiar archivo temporal
            if (Storage::exists($this->archivoPath)) {
                Storage::delete($this->archivoPath);
            }
            
        } catch (Exception $e) {
            Log::error("Error al limpiar después del fallo del Job", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}   