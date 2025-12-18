<?php

namespace App\Jobs;

use App\Imports\UsuariosAppImport; // ⭐ CAMBIO AQUÍ
use App\Models\ImportacionLog;
use App\Services\ImportUsuariosAppService; // ⭐ Y AQUÍ
use App\Traits\GeneraArchivoErrores;
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
    use GeneraArchivoErrores;

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

    public function handle(ImportUsuariosAppService $service): void // ⭐ SERVICIO CORRECTO
    {
        $importLog = ImportacionLog::findOrFail($this->importLogId);

        try {
            Log::info("🚀 Iniciando ImportarUsuariosAppJob", [
                'import_log_id' => $importLog->id,
                'archivo' => $this->archivoPath,
            ]);

            $importLog->marcarComoProcesando();

            if (!Storage::exists($this->archivoPath)) {
                throw new Exception("Archivo no encontrado: {$this->archivoPath}");
            }

            $absolutePath = Storage::path($this->archivoPath);
            $importLog->update(['total' => 0]);

            // ⭐ USAR LA CLASE CORRECTA
            Excel::import(
                new UsuariosAppImport($importLog, $service),
                $absolutePath
            );

            $importLog->marcarComoCompletada();

            if ($importLog->tieneErrores()) {
                $csvPath = $this->generarArchivoErrores($importLog);
                
                if ($csvPath) {
                    $importLog->update(['errores_archivo' => $csvPath]);
                }
            }

            Log::info("✅ ImportarUsuariosAppJob completado", [
                'import_log_id' => $importLog->id,
                'resumen' => $importLog->resumen,
            ]);

            if (Storage::exists($this->archivoPath)) {
                Storage::delete($this->archivoPath);
            }

        } catch (Exception $e) {
            Log::error("❌ Error en ImportarUsuariosAppJob", [
                'import_log_id' => $importLog->id,
                'error' => $e->getMessage(),
            ]);

            $importLog->marcarComoFallida($e->getMessage());

            throw $e;
        }
    }

    public function failed(Exception $exception): void
    {
        Log::error("💥 ImportarUsuariosAppJob falló definitivamente", [
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