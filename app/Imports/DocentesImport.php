<?php

namespace App\Imports;

use App\Models\ImportacionLog;
use App\Services\ImportDocentesService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithBatchInserts;

class DocentesImport implements
    ToCollection,
    WithHeadingRow,
    WithChunkReading,
    WithBatchInserts
{
    protected ImportacionLog $importLog;
    protected ImportDocentesService $service;
    protected int $offset = 0;

    public function __construct(ImportacionLog $importLog, ImportDocentesService $service)
    {
        $this->importLog = $importLog;
        $this->service = $service;
    }

    public function collection(Collection $rows): void
    {
        // Filtrar filas totalmente vacías
        $rows = $rows->filter(fn ($row) => !empty(array_filter($row->toArray())));

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

    public function chunkSize(): int
    {
        return 100; // Óptimo para 3000+ registros
    }

    public function batchSize(): int
    {
        return 100;
    }

    public function headingRow(): int
    {
        return 1;
    }
}