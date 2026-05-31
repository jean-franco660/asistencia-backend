<?php
// app/Exports/UsuariosAppErroresExport.php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class UsuariosAppErroresExport implements
    FromArray,
    WithHeadings,
    WithStyles,
    WithColumnWidths,
    WithEvents // ⭐ NUEVO
{
    protected $errores;

    public function __construct(array $errores)
    {
        $this->errores = $errores;
    }

    public function array(): array
    {
        $rows = [];

        // ⭐ Fila de instrucciones
        $rows[] = [
            ' INSTRUCCIONES',
            'Corrija los errores indicados y vuelva a importar este archivo.',
            '',
            '',
            '',
        ];

        // ⭐ Fila vacía
        $rows[] = [];

        foreach ($this->errores as $error) {
            $rows[] = [
                $error['fila'] ?? 'N/A',
                $error['codigo_docente'] ?? '',
                $error['docente'] ?? '',
                $error['codigo_modular_ie'] ?? '',
                $error['motivo'] ?? '',
            ];
        }

        return $rows;
    }

    public function headings(): array
    {
        return [
            'Fila Excel',
            'Código Docente',
            'Nombre Completo',
            'Código IE',
            'Motivo del Error',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // ⭐ NUEVO: Fila de instrucciones (fila 1)
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                    'size' => 12,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '0066CC'], // Azul
                ],
            ],
            // Headers (ahora en fila 3)
            3 => [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                    'size' => 11,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'DC3545'], // Rojo
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 12,
            'B' => 18,
            'C' => 50,
            'D' => 25,
            'E' => 20,
            'F' => 20,
            'G' => 25,
            'H' => 12,
            'I' => 18,
            'J' => 15,
            'K' => 20,
        ];
    }

    // ⭐ NUEVO: Eventos para personalización adicional
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                // Merge de la fila de instrucciones
                $event->sheet->mergeCells('A1:K1');

                // Altura de filas
                $event->sheet->getDelegate()->getRowDimension(1)->setRowHeight(30);
                $event->sheet->getDelegate()->getRowDimension(3)->setRowHeight(25);

                // Wrap text en columna de errores
                $event->sheet->getDelegate()->getStyle('C:C')->getAlignment()->setWrapText(true);

                // Auto-filtro en los headers
                $event->sheet->getDelegate()->setAutoFilter('A3:K3');

                // Congelar primera fila
                $event->sheet->getDelegate()->freezePane('A4');
            },
        ];
    }
}