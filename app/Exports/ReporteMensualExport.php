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

class ReporteMensualExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithColumnWidths, WithTitle
{
    protected $filters;

    public function __construct($filters = [])
    {
        $this->filters = $filters;
    }

    public function collection()
    {
        // Agrupar por mes, usuario e institución
        // Para simplificar, haremos una query de Asistencias y agruparemos en memoria o map
        // O mejor: Iteramos sobre Usuarios Activos y calculamos su mes.

        // Opción B: Query directa a Asistencias (es mas eficiente para reporte mensual)
        $query = Asistencia::with(['usuario', 'institucion'])
            ->selectRaw('usuario_app_id, institucion_id, DATE_FORMAT(fecha, "%Y-%m") as mes_anio')
            ->groupBy('usuario_app_id', 'institucion_id', 'mes_anio')
            ->orderBy('mes_anio', 'desc');

        if (!empty($this->filters['mes'])) { // Format YYYY-MM
            $query->having('mes_anio', $this->filters['mes']);
        }

        if (!empty($this->filters['institucion_id'])) {
            $query->where('institucion_id', $this->filters['institucion_id']);
        }

        // Get unique combinations
        $combinations = $query->get();

        return $combinations;
    }

    public function headings(): array
    {
        return [
            'Mes',
            'Docente',
            'Institución',
            'Días Hábiles', // Días laborales teóricos del mes
            'Días Presente',
            'Días Ausente',
            'Porcentaje'
        ];
    }

    public function map($row): array
    {
        // Row es un objeto parcial. Necesitamos re-consultar stats.
        $stats = Asistencia::where('usuario_app_id', $row->usuario_app_id)
            ->where('institucion_id', $row->institucion_id)
            ->whereRaw('DATE_FORMAT(fecha, "%Y-%m") = ?', [$row->mes_anio])
            ->get();

        $diasPresente = $stats->where('estado_diario', '!=', 'FALTA')->count();
        $diasAusente = $stats->where('estado_diario', 'FALTA')->count();
        $totalDias = $diasPresente + $diasAusente; // Asumiendo que FALTA se genera para dias habiles

        $porcentaje = $totalDias > 0 ? round(($diasPresente / $totalDias) * 100, 2) . '%' : '0%';

        $usuario = \App\Models\UsuarioApp::find($row->usuario_app_id);
        $institucion = \App\Models\Institucion::find($row->institucion_id);

        return [
            $row->mes_anio,
            $usuario ? $usuario->nombre_completo : '-',
            $institucion ? $institucion->nombre : '-',
            $totalDias,
            $diasPresente,
            $diasAusente,
            $porcentaje
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '805AD5']], // Purple
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 15,
            'B' => 35,
            'C' => 35,
            'D' => 15,
            'E' => 15,
            'F' => 15,
            'G' => 15
        ];
    }

    public function title(): string
    {
        return 'Reporte Mensual';
    }
}
