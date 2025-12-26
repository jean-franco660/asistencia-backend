<?php

namespace App\Exports;

use App\Models\UsuarioAppInstitucion;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Illuminate\Support\Facades\DB;

class InstitucionConsolidadoExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithColumnWidths, WithTitle
{
    protected $filters;

    public function __construct($filters = [])
    {
        $this->filters = $filters;
    }

    public function collection()
    {
        // Necesitamos agrupar por usuario e institución
        // Esta query puede ser compleja. Usaremos UsuarioAppInstitucion como base (Asignaciones vigentes o historial)

        $query = UsuarioAppInstitucion::with(['usuario', 'institucion'])
            ->where('estado', 'ACTIVO'); // Solo activos para consolidado actual? O todos? Usaremos filtro.

        if (!empty($this->filters['institucion_id'])) {
            $query->where('institucion_id', $this->filters['institucion_id']);
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'Institución',
            'Docente',
            'Total Días Asignados',
            'Total Asistencias',
            'Porcentaje Asistencia',
            'Último Acceso'
        ];
    }

    public function map($row): array
    {
        // Calcular métricas
        // Total días desde inicio asignación hasta hoy
        $totalDias = \Carbon\Carbon::parse($row->fecha_inicio)->diffInDays(now());

        // Asistencias reales
        $asistencias = $row->usuario->asistencias()
            ->where('institucion_id', $row->institucion_id)
            ->whereBetween('fecha', [$row->fecha_inicio, now()])
            ->where('estado_diario', '!=', 'FALTA') // Asumimos PRESENTE/TARDANZA cuentan
            ->count();

        $porcentaje = $totalDias > 0 ? round(($asistencias / $totalDias) * 100, 2) . '%' : '0%';

        // Ultimo acceso (ultima asistencia)
        $ultimoAcceso = $row->usuario->asistencias()
            ->where('institucion_id', $row->institucion_id)
            ->latest('fecha')
            ->first();

        return [
            $row->institucion->nombre ?? '-',
            $row->usuario ? $row->usuario->nombre_completo : '-',
            $totalDias,
            $asistencias,
            $porcentaje,
            $ultimoAcceso ? $ultimoAcceso->fecha->format('d/m/Y') : 'Nunca'
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2B6CB0']], // Blue
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 35,
            'B' => 35,
            'C' => 20,
            'D' => 20,
            'E' => 20,
            'F' => 20
        ];
    }

    public function title(): string
    {
        return 'Consolidado Institución';
    }
}
