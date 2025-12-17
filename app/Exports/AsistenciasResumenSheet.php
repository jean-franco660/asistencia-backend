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
        $query = Asistencia::query();

        // Aplicar filtros
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

        // Director → filtrar por sus instituciones
        if (!empty($this->filters['user'])) {
            $user = $this->filters['user'];
            if ($user->rol === 'director') {
                $institucionIds = $user->instituciones->pluck('id');
                $query->whereIn('institucion_id', $institucionIds);
            }
        }

        $asistencias = $query->get();

        $total = $asistencias->count();
        $a_tiempo = $asistencias->where('resultado', Asistencia::RESULTADO_A_TIEMPO)->count();
        $tarde = $asistencias->where('resultado', Asistencia::RESULTADO_TARDE)->count();
        $ausente = $asistencias->where('situacion', Asistencia::SITUACION_FALTA)->count();

        return collect([
            (object) [
                'estado' => 'A Tiempo',
                'cantidad' => $a_tiempo,
                'porcentaje' => $total > 0 ? round(($a_tiempo / $total) * 100, 1) : 0,
            ],
            (object) [
                'estado' => 'Tarde',
                'cantidad' => $tarde,
                'porcentaje' => $total > 0 ? round(($tarde / $total) * 100, 1) : 0,
            ],
            (object) [
                'estado' => 'Ausente',
                'cantidad' => $ausente,
                'porcentaje' => $total > 0 ? round(($ausente / $total) * 100, 1) : 0,
            ],
            (object) [
                'estado' => 'TOTAL',
                'cantidad' => $total,
                'porcentaje' => 100,
            ],
        ]);
    }

    public function headings(): array
    {
        return ['Estado', 'Cantidad', 'Porcentaje (%)'];
    }

    public function map($row): array
    {
        return [
            $row->estado,
            $row->cantidad,
            $row->porcentaje . '%',
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
            'A' => 20,
            'B' => 15,
            'C' => 18,
        ];
    }

    public function title(): string
    {
        return 'Resumen';
    }
}
