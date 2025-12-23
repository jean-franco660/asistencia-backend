<?php

namespace App\Imports;

use App\Models\ImportacionLog;
use App\Services\ImportInstitucionesService;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class InstitucionesImport implements
    ToCollection,
    WithHeadingRow,
    WithChunkReading
{
    protected ImportacionLog $importLog;
    protected ImportInstitucionesService $service;

    /**
     * Offset lógico de filas procesadas (para reportes/errores).
     * NO es el número real de fila en Excel.
     */
    protected int $offset = 0;

    protected bool $headersValidated = false;

    /**
     * Columnas requeridas (mínimas)
     */
    protected array $requiredHeaders = [
        'codigo_modular_ie',
        'nombre',
        'distrito',
        'nivel_educativo',
    ];

    /**
     * Columnas opcionales
     */
    protected array $optionalHeaders = [
        'tipo_gestion',
        'latitud',
        'longitud',
        'radio',
    ];

    public function __construct(ImportacionLog $importLog, ImportInstitucionesService $service)
    {
        $this->importLog = $importLog;
        $this->service = $service;
    }

    public function collection(Collection $rows): void
    {
        // 1️⃣ Validar headers solo una vez (primer chunk)
        if (!$this->headersValidated && $rows->isNotEmpty()) {
            $this->validateHeaders($rows->first());
            $this->headersValidated = true;
        }

        // 2️⃣ Filtrar filas realmente vacías
        $rows = $rows->filter(function ($row) {
            $arr = is_array($row) ? $row : $row->toArray();

            foreach ($arr as $v) {
                if (is_string($v)) {
                    if (trim($v) !== '')
                        return true;
                } elseif (!is_null($v)) {
                    return true;
                }
            }
            return false;
        });

        if ($rows->isEmpty()) {
            return;
        }

        try {
            // 3️⃣ Delegar todo al service (lógica + BD)
            $resultado = $this->service->procesarChunk(
                $rows,
                $this->importLog,
                $this->offset
            );

            // 4️⃣ Avanzar offset lógico (filas leídas)
            $this->offset += $rows->count();

            // 5️⃣ Log compacto por chunk (opcional)
            Log::info('Chunk instituciones procesado', [
                'import_log_id' => $this->importLog->id,
                'leidos_chunk' => $rows->count(),
                'offset' => $this->offset,
                'procesados_chunk' => $resultado['procesados_chunk'] ?? null,
                'exitosos_chunk' => $resultado['exitosos_chunk'] ?? null,
                'errores_chunk' => $resultado['errores_chunk'] ?? null,
            ]);

        } catch (Exception $e) {
            Log::error('Error procesando chunk de instituciones', [
                'import_log_id' => $this->importLog->id,
                'offset' => $this->offset,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Valida que el archivo tenga las columnas mínimas requeridas
     */
    protected function validateHeaders($firstRow): void
    {
        $headers = array_keys($firstRow->toArray());
        $missing = [];

        foreach ($this->requiredHeaders as $required) {
            if (!in_array($required, $headers, true)) {
                $missing[] = $required;
            }
        }

        if (!empty($missing)) {
            $message = 'El archivo no contiene las columnas requeridas: ' .
                implode(', ', $missing) .
                '. Descargue la plantilla oficial y asegúrese de que los encabezados coincidan exactamente.';

            Log::error('Validación de headers falló', [
                'import_log_id' => $this->importLog->id,
                'headers_encontrados' => $headers,
                'headers_faltantes' => $missing,
            ]);

            throw new Exception($message);
        }

        Log::info('Headers de instituciones validados correctamente', [
            'import_log_id' => $this->importLog->id,
            'headers' => $headers,
        ]);
    }

    /**
     * Tamaño de chunk recomendado para producción
     */
    public function chunkSize(): int
    {
        return 1000;
    }

    /**
     * Encabezados en fila 3 (2 filas de instrucciones)
     */
    public function headingRow(): int
    {
        return 3;
    }
}
