<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use App\Exports\AsistenciasResumenSheet;
use App\Exports\AsistenciasDetalleSheet;
use App\Exports\AsistenciasPorUsuariosAppSheet;


class AsistenciasMultipleExport implements WithMultipleSheets
{
    protected array $filters;

    public function __construct(array $filters = [])
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
            new AsistenciasPorUsuariosAppSheet($this->filters),
        ];
    }
}
