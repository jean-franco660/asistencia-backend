<?php

namespace App\Jobs;

use App\Imports\InstitucionesImport;
use App\Models\ImportacionLog;
use App\Services\ImportInstitucionesService;
use App\Traits\GeneraArchivoErrores; // ⭐ NUEVO
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportarInstitucionesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use GeneraArchivoErrores; // ⭐ NUEVO

    public $timeout = 7200;
    public $tries = 3;
    public $backoff = 300;
    public $maxExceptions = 3;

    protected int $importLogId;
    protected string $archivoPath;

    public function __construct(int $importLogId, string $archivoPath)
    {
        $this->importLogId = $importLogId;
        $this->archivoPath = $archivoPath;
    }

    public function handle(ImportInstitucionesService $service): void
    {
        $importLog = ImportacionLog::findOrFail($this->importLogId);

        try {
            Log::info("🚀 Iniciando ImportarInstitucionesJob", [
                'import_log_id' => $importLog->id,
                'archivo' => $this->archivoPath,
            ]);

            // Marcar como procesando
            $importLog->marcarComoProcesando(); // ✅ Usar método del modelo

            // Verificar archivo
            if (!Storage::exists($this->archivoPath)) {
                throw new Exception("Archivo no encontrado: {$this->archivoPath}");
            }

            $absolutePath = Storage::path($this->archivoPath);

            // ✅ SIMPLIFICADO: No contar filas aquí, se actualiza en el proceso
            $importLog->update(['total' => 0]); // Se actualizará durante chunks

            // Procesar importación
            Excel::import(
                new InstitucionesImport($importLog, $service),
                $absolutePath
            );

            // Marcar como completado
            $importLog->marcarComoCompletada(); // ✅ Usar método del modelo

            // ⭐ NUEVO: Generar archivo de errores si hay errores
            if ($importLog->tieneErrores()) {
                $csvPath = $this->generarArchivoErrores($importLog);
                
                if ($csvPath) {
                    $importLog->update(['errores_archivo' => $csvPath]);
                }
            }

            Log::info("✅ ImportarInstitucionesJob completado", [
                'import_log_id' => $importLog->id,
                'resumen' => $importLog->resumen,
            ]);

            // Limpiar archivo temporal
            if (Storage::exists($this->archivoPath)) {
                Storage::delete($this->archivoPath);
            }

        } catch (Exception $e) {
            Log::error("❌ Error en ImportarInstitucionesJob", [
                'import_log_id' => $importLog->id,
                'error' => $e->getMessage(),
            ]);

            $importLog->marcarComoFallida($e->getMessage()); // ✅ Usar método del modelo

            throw $e;
        }
    }

    public function failed(Exception $exception): void
    {
        Log::error("💥 ImportarInstitucionesJob falló definitivamente", [
            'import_log_id' => $this->importLogId,
            'error' => $exception->getMessage(),
        ]);

        try {
            $importLog = ImportacionLog::find($this->importLogId);
            
            if ($importLog && !$importLog->fallo()) {
                $importLog->marcarComoFallida($exception->getMessage());
            }

            if (Storage::exists($this->archivoPath)) {
                Storage::delete($this->archivoPath);
            }

        } catch (Exception $e) {
            Log::error("Error al limpiar", ['error' => $e->getMessage()]);
        }
    }
}