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

class AsistenciasPorUsuariosAppSheet implements
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
        // Consultamos Cabeceras Diarias para sacar estadísticas
        $query = Asistencia::with('usuario');

        if (!empty($this->filters['fecha_inicio'])) {
            $query->whereDate('fecha', '>=', $this->filters['fecha_inicio']);
        }
        if (!empty($this->filters['fecha_fin'])) {
            $query->whereDate('fecha', '<=', $this->filters['fecha_fin']);
        }
        if (!empty($this->filters['institucion_id'])) {
            $query->where('institucion_id', $this->filters['institucion_id']);
        }
        // El filtro 'tipo' (ENTRADA/SALIDA) no tiene sentido en resumen global por docente, lo ignoramos

        if (!empty($this->filters['user'])) {
            $user = $this->filters['user'];
            // Validar si es admin o supervisor
            if (!$user->esAdminOSuperAdmin()) {
                $institucionesIds = $user->institucionesVigentes()->pluck('id');
                if ($institucionesIds->isNotEmpty()) {
                    $query->whereIn('institucion_id', $institucionesIds);
                } else {
                    $query->whereRaw('1 = 0');
                }
            }
        }

        $asistencias = $query->get();

        $agrupado = $asistencias->groupBy('usuario_app_id')->map(function ($group) {
            $first = $group->first();
            $usuario = $first->usuario;

            $totalDias = $group->count();

            // Lógica basada en nuevos campos
            $faltas = $group->where('estado_diario', 'FALTA')->count();

            // Tardanza: si tiene minutos de tardanza > 0
            $tardanzas = $group->filter(function ($a) {
                return $a->minutos_tardanza > 0;
            })->count();

            // Presente y puntual
            $a_tiempo = $group->filter(function ($a) {
                return $a->estado_diario === 'PRESENTE' && $a->minutos_tardanza == 0;
            })->count();

            // Total asistidos (Presente o tardanza, NO falta)
            $totalAsistidos = $totalDias - $faltas;

            return (object) [
                'codigo' => $usuario->codigo_modular ?? '-',
                'nombre' => $usuario ? ($usuario->apellido_paterno . ' ' . $usuario->apellido_materno . ' ' . $usuario->nombres) : '-',
                'dni' => $usuario->dni ?? '-',
                'total_dias' => $totalDias,
                'a_tiempo' => $a_tiempo,
                'tardanzas' => $tardanzas,
                'faltas' => $faltas,
                'puntualidad' => $totalAsistidos > 0 ? round(($a_tiempo / $totalAsistidos) * 100, 1) : 0,
            ];
        });

        return $agrupado->values();
    }

    public function headings(): array
    {
        return [
            'Código Modular',
            'Docente',
            'DNI',
            'Días Reportados',
            'Puntuales',
            'Tardanzas',
            'Faltas',
            '% Puntualidad (sobre asistencias)'
        ];
    }

    public function map($row): array
    {
        return [
            $row->codigo,
            $row->nombre,
            $row->dni,
            $row->total_dias,
            $row->a_tiempo,
            $row->tardanzas,
            $row->faltas,
            $row->puntualidad . '%',
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
            'A' => 15, // Codigo
            'B' => 35, // Docente
            'C' => 12, // DNI
            'D' => 15, // Total Dias
            'E' => 12, // Puntuales
            'F' => 12, // Tardanzas
            'G' => 12, // Faltas
            'H' => 20, // %
        ];
    }

    public function title(): string
    {
        return 'Estadísticas por Docente';
    }
}
