<?php

namespace App\Jobs;

use App\Imports\InstitucionesImport;
use App\Models\ImportacionLog;
use App\Services\ImportInstitucionesService;
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

class ImportarInstitucionesJob implements ShouldQueue
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

    public function handle(ImportInstitucionesService $service): void
    {
        $importLog = ImportacionLog::findOrFail($this->importLogId);

        try {
            Log::info('🚀 Iniciando ImportarInstitucionesJob', [
                'import_log_id' => $importLog->id,
                'archivo' => $this->archivoPath,
            ]);

            $importLog->marcarComoProcesando();

            if (!Storage::exists($this->archivoPath)) {
                throw new Exception("Archivo no encontrado: {$this->archivoPath}");
            }

            $absolutePath = Storage::path($this->archivoPath);

            // (Opcional) reset limpio de contadores para la corrida actual.
            // Esto evita heredar valores si se reintenta el mismo import log.
            $importLog->update([
                'total' => 0,
                'procesados' => 0,
                'exitosos' => 0,
                'errores_count' => 0,
                'errores_detalle' => null,
                'errores_archivo' => null,
            ]);

            Excel::import(
                new InstitucionesImport($importLog, $service),
                $absolutePath
            );

            // Generar archivo de errores antes de cerrar (consistente)
            if ($importLog->tieneErrores()) {
                $pathErrores = $this->generarArchivoErrores($importLog);
                if ($pathErrores) {
                    $importLog->update(['errores_archivo' => $pathErrores]);
                }
            }

            // ✅ Normalizar TOTAL final (evita "de 0")
            $importLog->refresh();

            $exitosos = (int) ($importLog->exitosos ?? 0);
            $errores  = (int) ($importLog->errores_count ?? 0);
            $procesados = (int) ($importLog->procesados ?? 0);

            if ((int) ($importLog->total ?? 0) <= 0) {
                $total = $procesados > 0 ? $procesados : ($exitosos + $errores);

                $importLog->update([
                    'total' => $total,
                    'procesados' => $procesados > 0 ? $procesados : $total,
                ]);
            }

            $importLog->marcarComoCompletada();

            Log::info('✅ ImportarInstitucionesJob completado', [
                'import_log_id' => $importLog->id,
                'resumen' => $importLog->resumen,
            ]);

            Storage::delete($this->archivoPath);

        } catch (Exception $e) {
            Log::error('❌ Error en ImportarInstitucionesJob', [
                'import_log_id' => $importLog->id,
                'error' => $e->getMessage(),
            ]);

            $importLog->marcarComoFallida($e->getMessage());
            throw $e;
        }
    }

    public function failed(Exception $exception): void
    {
        Log::error('💥 ImportarInstitucionesJob falló definitivamente', [
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
            Log::error('Error al limpiar', ['error' => $e->getMessage()]);
        }
    }
}
