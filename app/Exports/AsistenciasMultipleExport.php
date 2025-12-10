<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class AsistenciasMultipleExport implements WithMultipleSheets
{
    protected $filters;

    public function __construct($filters = [])
    {
        $this->filters = $filters;
    }

    /**
     * Retornar las hojas del Excel
     */
    public function sheets(): array
    {
        return [
            new AsistenciasResumenSheet($this->filters),
            new AsistenciasDetalleSheet($this->filters),
            new AsistenciasPorDocenteSheet($this->filters),
        ];
    }
}