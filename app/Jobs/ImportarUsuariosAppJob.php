<?php

namespace App\Jobs;

use App\Imports\UsuariosAppImport;
use App\Models\ImportacionLog;
use App\Services\ImportUsuariosAppService;
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

    public function handle(ImportUsuariosAppService $service): void
    {
        $importLog = ImportacionLog::findOrFail($this->importLogId);

        try {
            Log::info(' Iniciando ImportarUsuariosAppJob', [
                'import_log_id' => $importLog->id,
                'archivo' => $this->archivoPath,
            ]);

            $importLog->marcarComoProcesando();

            if (!Storage::exists($this->archivoPath)) {
                throw new Exception("Archivo no encontrado: {$this->archivoPath}");
            }

            $absolutePath = Storage::path($this->archivoPath);

            //  Importación
            Excel::import(
                new UsuariosAppImport($importLog, $service),
                $absolutePath
            );

            //  Generar archivo de errores antes de cerrar
            if ($importLog->tieneErrores()) {
                $pathErrores = $this->generarArchivoErrores($importLog);
                if ($pathErrores) {
                    $importLog->update(['errores_archivo' => $pathErrores]);
                }
            }

            //  Normalizar contadores finales
            $importLog->refresh();

            $exitosos  = (int) $importLog->exitosos;
            $errores   = (int) $importLog->errores_count;
            $procesados = (int) $importLog->procesados;

            if ($importLog->total <= 0) {
                $total = $procesados > 0
                    ? $procesados
                    : ($exitosos + $errores);

                $importLog->update([
                    'total' => $total,
                    'procesados' => $procesados > 0 ? $procesados : $total,
                ]);
            }

            $importLog->marcarComoCompletada();

            Log::info(' ImportarUsuariosAppJob completado', [
                'import_log_id' => $importLog->id,
                'resumen' => $importLog->resumen,
            ]);

            Storage::delete($this->archivoPath);

        } catch (Exception $e) {
            Log::error(' Error en ImportarUsuariosAppJob', [
                'import_log_id' => $importLog->id,
                'error' => $e->getMessage(),
            ]);

            $importLog->marcarComoFallida($e->getMessage());
            throw $e;
        }
    }

    public function failed(Exception $exception): void
    {
        Log::error(' ImportarUsuariosAppJob falló definitivamente', [
            'import_log_id' => $this->importLogId,
            'error' => $exception->getMessage(),
        ]);
    }
}
