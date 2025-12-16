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
use Maatwebsite\Excel\Concerns\WithValidation;

class InstitucionesImport implements
    ToCollection,
    WithHeadingRow,
    WithChunkReading,
    WithValidation
{
    protected ImportacionLog $importLog;
    protected ImportInstitucionesService $service;
    protected int $offset = 0;
    protected bool $headersValidated = false;

    // Columnas requeridas en el orden esperado
    protected array $requiredHeaders = [
        'codigo_modular_ie',
        'nombre',
        'distrito',
    ];

    // Columnas opcionales
    protected array $optionalHeaders = [
        'direccion',
        'nivel_educativo',
        'centro_poblado',
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
        // Validar headers en el primer chunk
        if (!$this->headersValidated && $rows->isNotEmpty()) {
            $this->validateHeaders($rows->first());
            $this->headersValidated = true;
        }

        // Filtrar filas vacías
        $rows = $rows->filter(fn($row) => !empty(array_filter($row->toArray())));

        if ($rows->isEmpty()) {
            return;
        }

        try {
            $resultado = $this->service->procesarChunk(
                $rows,
                $this->importLog,
                $this->offset
            );

            // Avanza offset según lo realmente procesado
            $this->offset += (int) ($resultado['procesados'] ?? $rows->count());

        } catch (Exception $e) {
            Log::error("Error procesando chunk de instituciones", [
                'import_log_id' => $this->importLog->id,
                'offset' => $this->offset,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Valida que el archivo tenga las columnas requeridas
     */
    protected function validateHeaders($firstRow): void
    {
        $headers = array_keys($firstRow->toArray());
        $missingHeaders = [];

        foreach ($this->requiredHeaders as $required) {
            if (!in_array($required, $headers)) {
                $missingHeaders[] = $required;
            }
        }

        if (!empty($missingHeaders)) {
            $message = 'El archivo no contiene las columnas requeridas: ' . implode(', ', $missingHeaders) . '. ' .
                'Por favor, descargue la plantilla oficial y asegúrese de que los encabezados coincidan exactamente.';

            Log::error("Validación de headers falló", [
                'import_log_id' => $this->importLog->id,
                'headers_encontrados' => $headers,
                'headers_faltantes' => $missingHeaders,
            ]);

            throw new Exception($message);
        }

        Log::info("Headers validados correctamente", [
            'import_log_id' => $this->importLog->id,
            'headers' => $headers,
        ]);
    }

    public function rules(): array
    {
        return [];
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function headingRow(): int
    {
        return 1;
    }
}