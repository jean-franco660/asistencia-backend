<?php

namespace App\Exports;

use App\Models\UsuarioAppInstitucion; // Asumo este modelo para asignaciones
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class DocenteHistorialExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithColumnWidths, WithTitle
{
    protected $filters;

    public function __construct($filters = [])
    {
        $this->filters = $filters;
    }

    public function collection()
    {
        $query = UsuarioAppInstitucion::with(['usuario', 'institucion'])
            ->orderBy('created_at', 'desc');

        if (!empty($this->filters['usuario_id'])) {
            $query->where('usuario_app_id', $this->filters['usuario_id']);
        }

        // Si se filtra por rango de fecha, podriamos filtrar por fecha_inicio
        if (!empty($this->filters['fecha_inicio'])) {
            $query->whereDate('fecha_inicio', '>=', $this->filters['fecha_inicio']);
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'Docente',
            'Institución',
            'Fecha Inicio Asignación',
            'Fecha Fin Asignación',
            'Estado Asignación',
            'Total Días Trabajados', // Calculado o dummy por ahora
            'Total Asistencias',     // Calculado
        ];
    }

    public function map($row): array
    {
        // Calcular total asistencias?
        // Esto podría ser costoso si hay muchos registros. Idealmente usar withCount en la query.
        $totalAsistencias = $row->usuario->asistencias()
            ->where('institucion_id', $row->institucion_id)
            ->whereBetween('fecha', [$row->fecha_inicio, $row->fecha_fin ?? now()])
            ->count();

        $diasTrabajados = \Carbon\Carbon::parse($row->fecha_inicio)->diffInDays($row->fecha_fin ?? now());

        return [
            $row->usuario ? ($row->usuario->apellido_paterno . ' ' . $row->usuario->nombres) : '-',
            $row->institucion->nombre ?? '-',
            $row->fecha_inicio ? $row->fecha_inicio->format('d/m/Y') : '-',
            $row->fecha_fin ? $row->fecha_fin->format('d/m/Y') : 'Vigente',
            $row->estado,
            $diasTrabajados,
            $totalAsistencias
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2F855A']], // Green
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 35,
            'B' => 35,
            'C' => 15,
            'D' => 15,
            'E' => 15,
            'F' => 20,
            'G' => 20
        ];
    }

    public function title(): string
    {
        return 'Historial Docente';
    }
}
