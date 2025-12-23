<?php

namespace App\Imports;

use App\Models\ImportacionLog;
use App\Services\ImportUsuariosAppService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithBatchInserts;

class UsuariosAppImport implements
    ToCollection,
    WithHeadingRow,
    WithChunkReading,
    WithBatchInserts
{
    protected ImportacionLog $importLog;
    protected ImportUsuariosAppService $service;
    protected int $offset = 0;
    protected bool $headersValidated = false;

    /**
     * Columnas requeridas (mínimas)
     */
    protected array $requiredHeaders = [
        'codigo_modular',
        'dni',
        'apellido_paterno',
        'nombres',
        'codigo_modular_ie',
        'cargo',
    ];

    /**
     * Columnas opcionales
     */
    protected array $optionalHeaders = [
        'apellido_materno',
        'sexo',
        'telefono',
        'password',
    ];

    public function __construct(ImportacionLog $importLog, ImportUsuariosAppService $service)
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

        // 2️⃣ Filtrar filas totalmente vacías
        $rows = $rows->filter(fn($row) => !empty(array_filter($row->toArray())));

        if ($rows->isEmpty()) {
            return;
        }

        try {
            // Tu service se encarga de:
            // - cache de instituciones por chunk
            // - crear/actualizar docentes
            // - attach pivot docente_institucion sin duplicados
            // - acumular y guardar errores_detalle
            // - actualizar contadores en ImportacionLog
            $resultado = $this->service->procesarChunk(
                $rows,
                $this->importLog,
                $this->offset
            );

            // Avanza offset según lo realmente procesado
            $this->offset += (int) ($resultado['procesados'] ?? $rows->count());

        } catch (\Exception $e) {
            Log::error("Error procesando chunk de docentes", [
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

            Log::error('Validación de headers falló (usuarios app)', [
                'import_log_id' => $this->importLog->id,
                'headers_encontrados' => $headers,
                'headers_faltantes' => $missing,
            ]);

            throw new \Exception($message);
        }

        Log::info('Headers de usuarios app validados correctamente', [
            'import_log_id' => $this->importLog->id,
            'headers' => $headers,
        ]);
    }

    public function chunkSize(): int
    {
        return 1000;
    }
    public function batchSize(): int
    {
        return 1000;
    }


    public function headingRow(): int
    {
        return 3;  // ✅ Los encabezados están en la fila 3 (después de 2 filas de instrucciones)
    }
}