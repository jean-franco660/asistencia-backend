<?php

namespace App\Exports;

use App\Models\Asistencia;
use App\Models\AsistenciaDiaria;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Carbon\Carbon;

class AsistenciasDetalleSheet implements
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
        // Consultamos Marcaciones Individuales
        $query = AsistenciaDiaria::with(['asistencia.usuario', 'asistencia.institucion'])
            ->orderBy('marcada_en', 'desc');

        // Filtros
        if (!empty($this->filters['fecha_inicio'])) {
            // Filtramos por el momento exacto de la marcación
            $query->whereDate('marcada_en', '>=', $this->filters['fecha_inicio']);
        }
        if (!empty($this->filters['fecha_fin'])) {
            $query->whereDate('marcada_en', '<=', $this->filters['fecha_fin']);
        }

        // Filtros via relación Asistencia (Cabecera)
        if (!empty($this->filters['institucion_id'])) {
            $query->whereHas('asistencia', function ($q) {
                $q->where('institucion_id', $this->filters['institucion_id']);
            });
        }

        if (!empty($this->filters['tipo'])) {
            $query->where('tipo', $this->filters['tipo']);
        }

        // Filtro Supervisor
        // Filtro Supervisor
        if (!empty($this->filters['user'])) {
            $user = $this->filters['user'];
            if (!$user->esAdminOSuperAdmin()) {
                $institucionesIds = $user->institucionesVigentes()->pluck('id');

                $query->whereHas('asistencia', function ($q) use ($institucionesIds) {
                    $q->whereIn('institucion_id', $institucionesIds);
                });
            }
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'N°',
            'Docente',
            'DNI',
            'Institución',
            'Fecha',
            'Hora',
            'Tipo',
            'Estado Marcación', // VALIDA, OBSERVADA
            'Estado Revisión',  // PENDIENTE, APROBADA
            'Dentro Rango',
            'Motivo',
            'Latitud',
            'Longitud',
            'Revisado Por'
        ];
    }

    public function map($row): array
    {
        static $index = 0;
        $index++;

        $header = $row->asistencia;
        $usuario = $header ? $header->usuario : null;
        $institucion = $header ? $header->institucion : null;

        return [
            $index,
            $usuario ? ($usuario->apellido_paterno . ' ' . $usuario->nombres) : '-',
            $usuario->dni ?? '-',
            $institucion->nombre ?? '-',
            $row->marcada_en ? Carbon::parse($row->marcada_en)->setTimezone('America/Lima')->format('d/m/Y') : '-',
            $row->marcada_en ? Carbon::parse($row->marcada_en)->setTimezone('America/Lima')->format('H:i:s') : '-',
            $row->tipo,
            $row->estado_marcacion,
            $row->estado_revision ?? '-',
            $row->dentro_rango ? 'SÍ' : 'NO',
            $row->motivo ?? '-',
            number_format($row->latitud ?? 0, 6),
            number_format($row->longitud ?? 0, 6),
            $row->revisadoPor ? $row->revisadoPor->email : '-'
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'EA580C'] // Naranja quemado para diferenciar
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 5,  // N
            'B' => 30, // Docente
            'C' => 12, // DNI
            'D' => 30, // Inst
            'E' => 12, // Fecha
            'F' => 10, // Hora
            'G' => 10, // Tipo
            'H' => 15, // Est Marc
            'I' => 15, // Est Rev
            'J' => 10, // Rango
            'K' => 20, // Motivo
            'L' => 12, // Lat
            'M' => 12, // Lon
            'N' => 25  // Revisado Por
        ];
    }

    public function title(): string
    {
        return 'Marcaciones (Detalle)';
    }
}
