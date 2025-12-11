<?php

namespace App\Exports;

use App\Models\Asistencia;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * ============================================
 * HOJA 1: RESUMEN DE ESTADÍSTICAS
 * ============================================
 */
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

        // Si es director, filtrar por sus instituciones
        if (!empty($this->filters['user'])) {
            $user = $this->filters['user'];
            if ($user->rol === 'director') {
                $institucionIds = $user->instituciones->pluck('id');
                $query->whereIn('institucion_id', $institucionIds);
            }
        }

        $asistencias = $query->get();

        $total = $asistencias->count();
        $a_tiempo = $asistencias->where('estado', 'a_tiempo')->count();
        $tarde = $asistencias->where('estado', 'tarde')->count();
        $ausente = $asistencias->where('falta', true)->count();

        return collect([
            (object)[
                'estado' => 'A Tiempo',
                'cantidad' => $a_tiempo,
                'porcentaje' => $total > 0 ? round(($a_tiempo / $total) * 100, 1) : 0,
            ],
            (object)[
                'estado' => 'Tarde',
                'cantidad' => $tarde,
                'porcentaje' => $total > 0 ? round(($tarde / $total) * 100, 1) : 0,
            ],
            (object)[
                'estado' => 'Ausente',
                'cantidad' => $ausente,
                'porcentaje' => $total > 0 ? round(($ausente / $total) * 100, 1) : 0,
            ],
            (object)[
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

/**
 * ============================================
 * HOJA 2: DETALLE COMPLETO DE ASISTENCIAS
 * ============================================
 */
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

        // Si es director, filtrar por sus instituciones
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
            'A' => 5,  // N°
            'B' => 30, // Docente
            'C' => 12, // Código
            'D' => 12, // DNI
            'E' => 35, // Institución
            'F' => 12, // Fecha
            'G' => 10, // Hora
            'H' => 10, // Tipo
            'I' => 18, // Estado
            'J' => 15, // Ubicación
            'K' => 12, // Latitud
            'L' => 12, // Longitud
        ];
    }

    public function title(): string
    {
        return 'Detalle';
    }
}

/**
 * ============================================
 * HOJA 3: AGRUPADO POR DOCENTE
 * ============================================
 */
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

        // Si es director, filtrar por sus instituciones
        if (!empty($this->filters['user'])) {
            $user = $this->filters['user'];
            if ($user->rol === 'director') {
                $institucionIds = $user->instituciones->pluck('id');
                $query->whereIn('institucion_id', $institucionIds);
            }
        }

        $asistencias = $query->get();

        // Agrupar por docente
        $agrupado = $asistencias->groupBy('usuario_id')->map(function ($group) {
            $usuario = $group->first()->usuario;
            $total = $group->count();
            $a_tiempo = $group->where('estado', 'a_tiempo')->count();
            $tarde = $group->where('estado', 'tarde')->count();
            $ausente = $group->where('falta', true)->count();

            return (object)[
                'codigo' => $usuario->codigo ?? '-',
                'nombre' => $usuario->nombre ?? '-',
                'dni' => $usuario->dni ?? '-',
                'total' => $total,
                'a_tiempo' => $a_tiempo,
                'tarde' => $tarde,
                'ausente' => $ausente,
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
            'A' => 12, // Código
            'B' => 30, // Docente
            'C' => 12, // DNI
            'D' => 15, // Total
            'E' => 12, // A Tiempo
            'F' => 12, // Tarde
            'G' => 12, // Ausente
            'H' => 15, // % Puntualidad
        ];
    }

    public function title(): string
    {
        return 'Por Docente';
    }
}