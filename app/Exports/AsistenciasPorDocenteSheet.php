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

class AsistenciasPorDocenteSheet implements 
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
        $query = Asistencia::with('usuario');

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

        $asistencias = $query->get();

        $agrupado = $asistencias->groupBy('usuario_id')->map(function ($group) {
            $usuario = $group->first()->usuario;
            $total   = $group->count();
            $a_tiempo = $group->where('estado', 'a_tiempo')->count();
            $tarde    = $group->where('estado', 'tarde')->count();
            $ausente  = $group->where('falta', true)->count();

            return (object)[
                'codigo'      => $usuario->codigo ?? '-',
                'nombre'      => $usuario->nombre ?? '-',
                'dni'         => $usuario->dni ?? '-',
                'total'       => $total,
                'a_tiempo'    => $a_tiempo,
                'tarde'       => $tarde,
                'ausente'     => $ausente,
                'puntualidad' => $total > 0 ? round(($a_tiempo / $total) * 100, 1) : 0,
            ];
        });

        return $agrupado->values();
    }

    public function headings(): array
    {
        return [
            'Código',
            'Docente',
            'DNI',
            'Total Registros',
            'A Tiempo',
            'Tarde',
            'Ausente',
            '% Puntualidad'
        ];
    }

    public function map($row): array
    {
        return [
            $row->codigo,
            $row->nombre,
            $row->dni,
            $row->total,
            $row->a_tiempo,
            $row->tarde,
            $row->ausente,
            $row->puntualidad . '%',
        ];
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
            'A' => 12,
            'B' => 30,
            'C' => 12,
            'D' => 15,
            'E' => 12,
            'F' => 12,
            'G' => 12,
            'H' => 15,
        ];
    }

    public function title(): string
    {
        return 'Por Docente';
    }
}
