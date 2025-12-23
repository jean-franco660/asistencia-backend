<?php

namespace App\Exports;

// Modelos
use App\Models\Asistencia;
use App\Models\Institucion;

// Laravel
use Illuminate\Support\Collection;
use Carbon\Carbon;

// Maatwebsite Excel
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

// PhpSpreadsheet
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class InstitucionReportExport implements
    FromCollection,
    WithHeadings,
    WithMapping,
    WithStyles,
    WithColumnWidths,
    WithTitle,
    WithEvents
{
    protected $institucionId;
    protected $fechaInicio;
    protected $fechaFin;
    protected $institucionNombre;
    protected $rowCount = 0;

    public function __construct($institucionId, $fechaInicio = null, $fechaFin = null)
    {
        $this->institucionId = $institucionId;
        $this->fechaInicio = $fechaInicio;
        $this->fechaFin = $fechaFin;
        
        // Obtener nombre de institución para el título
        $institucion = Institucion::find($institucionId);
        $this->institucionNombre = $institucion->nombre ?? 'Institución';
    }

    public function collection()
    {
        $query = Asistencia::with(['usuario', 'horario'])
            ->where('institucion_id', $this->institucionId)
            ->orderBy('fecha', 'desc')
            ->orderBy('usuario_app_id');

        if ($this->fechaInicio) {
            $query->whereDate('fecha', '>=', $this->fechaInicio);
        }
        if ($this->fechaFin) {
            $query->whereDate('fecha', '<=', $this->fechaFin);
        }

        $collection = $query->get();
        $this->rowCount = $collection->count();
        
        return $collection;
    }

    public function headings(): array
    {
        return [
            'Fecha',
            'Código Modular',
            'DNI',
            'Apellidos y Nombres',
            'Turno',
            'Hora Entrada',
            'Hora Salida',
            'Tardanza (min)',
            'Estado',
            'Observación'
        ];
    }

    public function map($row): array
    {
        // Formatear nombre completo
        $nombreCompleto = trim(
            ($row->usuario->apellido_paterno ?? '') . ' ' .
            ($row->usuario->apellido_materno ?? '') . ' ' .
            ($row->usuario->nombres ?? '')
        ) ?: '-';

        // Estado con formato mejorado
        $estado = $this->formatearEstado($row->estado_diario);

        return [
            $row->fecha ? $row->fecha->format('d/m/Y') : '-',
            $row->usuario->codigo_modular ?? '-',
            $row->usuario->dni ?? '-',
            $nombreCompleto,
            $row->horario->nombre_turno ?? '-',
            $row->hora_entrada ?? '-',
            $row->hora_salida ?? '-',
            $row->minutos_tardanza > 0 ? $row->minutos_tardanza : '0',
            $estado,
            $row->observacion ?? '-'
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $lastRow = $this->rowCount + 1; // +1 por el encabezado

        // Estilo del encabezado
        $sheet->getStyle('A1:J1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 11
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1E40AF'] // Azul más oscuro
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
        ]);

        // Altura de la fila del encabezado
        $sheet->getRowDimension(1)->setRowHeight(25);

        // Alineación del contenido
        $sheet->getStyle('A2:A' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B2:C' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('E2:H' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('I2:I' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('D2:D' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('J2:J' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Bordes para todo el contenido
        $sheet->getStyle('A1:J' . $lastRow)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D1D5DB'],
                ],
            ],
        ]);

        // Alternar colores de fila para mejor legibilidad
        for ($i = 2; $i <= $lastRow; $i++) {
            if ($i % 2 == 0) {
                $sheet->getStyle('A' . $i . ':J' . $i)->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'F9FAFB']
                    ]
                ]);
            }
        }

        // Formato condicional para tardanzas
        for ($i = 2; $i <= $lastRow; $i++) {
            $tardanza = $sheet->getCell('H' . $i)->getValue();
            if (is_numeric($tardanza) && $tardanza > 0) {
                $sheet->getStyle('H' . $i)->applyFromArray([
                    'font' => [
                        'color' => ['rgb' => 'DC2626'],
                        'bold' => true
                    ]
                ]);
            }
        }

        return [];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 12,  // Fecha
            'B' => 16,  // Código Modular
            'C' => 12,  // DNI
            'D' => 40,  // Apellidos y Nombres
            'E' => 15,  // Turno
            'F' => 13,  // Hora Entrada
            'G' => 13,  // Hora Salida
            'H' => 15,  // Tardanza
            'I' => 15,  // Estado
            'J' => 35,  // Observación
        ];
    }

    public function title(): string
    {
        $periodo = '';
        if ($this->fechaInicio && $this->fechaFin) {
            $periodo = Carbon::parse($this->fechaInicio)->format('d-m-Y') . 
                      ' al ' . 
                      Carbon::parse($this->fechaFin)->format('d-m-Y');
        } elseif ($this->fechaInicio) {
            $periodo = 'Desde ' . Carbon::parse($this->fechaInicio)->format('d-m-Y');
        } elseif ($this->fechaFin) {
            $periodo = 'Hasta ' . Carbon::parse($this->fechaFin)->format('d-m-Y');
        } else {
            $periodo = 'Todo el periodo';
        }

        return substr('Asistencias - ' . $periodo, 0, 31); // Excel limita a 31 caracteres
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                
                // Aplicar autofiltro
                $sheet->setAutoFilter('A1:J1');

                // Congelar primera fila
                $sheet->freezePane('A2');

                // Agregar resumen al final
                $this->agregarResumen($sheet);
            },
        ];
    }

    private function agregarResumen(Worksheet $sheet)
    {
        if ($this->rowCount == 0) return;

        $lastRow = $this->rowCount + 1;
        $summaryRow = $lastRow + 2;

        // Título del resumen
        $sheet->setCellValue('A' . $summaryRow, 'RESUMEN');
        $sheet->mergeCells('A' . $summaryRow . ':B' . $summaryRow);
        $sheet->getStyle('A' . $summaryRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E5E7EB']
            ]
        ]);

        // Estadísticas
        $summaryRow++;
        $sheet->setCellValue('A' . $summaryRow, 'Total de registros:');
        $sheet->setCellValue('B' . $summaryRow, $this->rowCount);
        
        $summaryRow++;
        $sheet->setCellValue('A' . $summaryRow, 'Total tardanzas:');
        $sheet->setCellValue('B' . $summaryRow, '=COUNTIF(H2:H' . $lastRow . ',">0")');
        
        $summaryRow++;
        $sheet->setCellValue('A' . $summaryRow, 'Promedio tardanza (min):');
        $sheet->setCellValue('B' . $summaryRow, '=ROUND(AVERAGE(H2:H' . $lastRow . '),2)');

        // Estilo del resumen
        $sheet->getStyle('A' . ($summaryRow - 2) . ':B' . $summaryRow)->applyFromArray([
            'font' => ['bold' => true],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ]
            ]
        ]);
    }

    private function formatearEstado($estado): string
    {
        $estados = [
            'presente' => 'PRESENTE',
            'ausente' => 'AUSENTE',
            'tardanza' => 'TARDANZA',
            'justificado' => 'JUSTIFICADO',
            'licencia' => 'LICENCIA'
        ];

        return $estados[strtolower($estado)] ?? strtoupper($estado);
    }
}