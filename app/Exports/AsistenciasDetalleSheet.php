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
        $query = Asistencia::with(['usuario', 'institucion'])
            ->orderBy('fecha_hora', 'desc');

        if (!empty($this->filters['fecha_inicio'])) {
            $query->whereDate('fecha_hora', '>=', $this->filters['fecha_inicio']);
        }
        if (!empty($this->filters['fecha_fin'])) {
            $query->whereDate('fecha_hora', '<=', $this->filters['fecha_fin']);
        }
        if (!empty($this->filters['institucion_id'])) {
            $query->where('institucion_id', $this->filters['institucion_id']);
        }
        if (!empty($this->filters['tipo'])) {
            $query->where('tipo', $this->filters['tipo']);
        }

        if (!empty($this->filters['user'])) {
            $user = $this->filters['user'];
            if ($user->rol === 'director') {
                $institucionIds = $user->instituciones->pluck('id');
                $query->whereIn('institucion_id', $institucionIds);
            }
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'N°',
            'Docente',
            'Código',
            'DNI',
            'Institución',
            'Fecha',
            'Hora',
            'Tipo',
            'Estado',
            'Ubicación',
            'Latitud',
            'Longitud',
        ];
    }

    public function map($asistencia): array
    {
        static $index = 0;
        $index++;

        return [
            $index,
            $asistencia->usuario->nombre ?? '-',
            $asistencia->usuario->codigo ?? '-',
            $asistencia->usuario->dni ?? '-',
            $asistencia->institucion->nombre ?? '-',
            $asistencia->fecha_hora ? $asistencia->fecha_hora->format('d/m/Y') : '-',
            $asistencia->fecha_hora ? $asistencia->fecha_hora->format('H:i:s') : '-',
            $asistencia->tipo === 'entrada' ? 'Entrada' : 'Salida',
            $this->getEstadoTexto($asistencia),
            $asistencia->dentro_rango ? 'En rango' : 'Fuera de rango',
            number_format($asistencia->latitud ?? 0, 6),
            number_format($asistencia->longitud ?? 0, 6),
        ];
    }

    private function getEstadoTexto($asistencia): string
    {
        if ($asistencia->falta) return 'Ausente';
        if ($asistencia->estado === 'a_tiempo') return 'A Tiempo';
        if ($asistencia->estado === 'tarde') return 'Tarde';
        if ($asistencia->estado === 'salida_antes') return 'Salida Anticipada';
        return '-';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4472C4']
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 5,
            'B' => 30,
            'C' => 12,
            'D' => 12,
            'E' => 35,
            'F' => 12,
            'G' => 10,
            'H' => 10,
            'I' => 18,
            'J' => 15,
            'K' => 12,
            'L' => 12,
        ];
    }

    public function title(): string
    {
        return 'Detalle';
    }
}
