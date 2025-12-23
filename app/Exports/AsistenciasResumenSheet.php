<?php

namespace App\Exports;

use App\Models\Asistencia;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class AsistenciasResumenSheet implements
    FromCollection,
    WithHeadings,
    WithMapping,
    WithStyles,
    WithColumnWidths,
    WithTitle
{
    protected $filters;

    public function __construct($filters = [])
    {
        $this->filters = $filters;
    }

    public function collection()
    {
        $query = Asistencia::with(['usuario', 'institucion', 'horario'])
            ->orderBy('fecha', 'desc');

        // Aplicar filtros
        if (!empty($this->filters['fecha_inicio'])) {
            $query->whereDate('fecha', '>=', $this->filters['fecha_inicio']);
        }
        if (!empty($this->filters['fecha_fin'])) {
            $query->whereDate('fecha', '<=', $this->filters['fecha_fin']);
        }
        if (!empty($this->filters['institucion_id'])) {
            $query->where('institucion_id', $this->filters['institucion_id']);
        }

        // El filtro 'tipo' (ENTRADA/SALIDA) no aplica bien a cabeceras diarias, 
        // pero podríamos filtrar si tiene hora_entrada o hora_salida si fuera necesario.
        // Lo ignoraremos para el resumen diario o lo usamos para checkear marcaciones.

        // Filtrar por supervisor
        if (!empty($this->filters['user'])) {
            $user = $this->filters['user'];
            if (!$user->esAdministrador()) {
                $institucionesIds = $user->institucionesVigentes()->pluck('id');
                if ($institucionesIds->isNotEmpty()) {
                    $query->whereIn('institucion_id', $institucionesIds);
                } else {
                    $query->whereRaw('1 = 0'); // No results
                }
            }
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'Fecha',
            'Docente',
            'DNI',
            'Institución',
            'Turno',
            'Estado Diario',
            'H. Entrada',
            'H. Salida',
            'Min Tardanza',
            'Observación'
        ];
    }

    public function map($row): array
    {
        return [
            $row->fecha ? $row->fecha->format('d/m/Y') : '-',
            $row->usuario ? ($row->usuario->apellido_paterno . ' ' . $row->usuario->apellido_materno . ' ' . $row->usuario->nombres) : '-',
            $row->usuario->numero_documento ?? '-',
            $row->institucion->nombre ?? '-',
            $row->horario->nombre_turno ?? '-',
            $row->estado_diario,
            $row->hora_entrada ?? '-',
            $row->hora_salida ?? '-',
            $row->minutos_tardanza > 0 ? $row->minutos_tardanza : '0',
            $row->observacion ?? '-'
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4472C4']
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 12, // Fecha
            'B' => 35, // Docente
            'C' => 12, // DNI
            'D' => 35, // Institución
            'E' => 15, // Turno
            'F' => 15, // Estado
            'G' => 12, // Entrada
            'H' => 12, // Salida
            'I' => 12, // Min Tadanza
            'J' => 30, // Observacion
        ];
    }

    public function title(): string
    {
        return 'Resumen Diario';
    }
}
