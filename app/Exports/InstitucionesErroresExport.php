<?php
// app/Exports/InstitucionesErroresExport.php

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

class InstitucionesErroresExport implements 
    FromArray, 
    WithHeadings, 
    WithStyles, 
    WithColumnWidths,
    WithEvents
{
    protected $errores;

    public function __construct(array $errores)
    {
        $this->errores = $errores;
    }

    public function array(): array
    {
        $rows = [];
        
        // Fila de instrucciones
        $rows[] = [
            '📋 INSTRUCCIONES',
            'Corrija los errores en las columnas correspondientes y vuelva a importar este archivo.',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ];
        
        // Fila vacía
        $rows[] = [];
        
        foreach ($this->errores as $error) {
            $rows[] = [
                $error['fila'] ?? 'N/A',
                $error['codigo'] ?? 'N/A',
                implode(' | ', $error['errores'] ?? []),
                $error['datos']['codigo_modular_ie'] ?? '',
                $error['datos']['nombre'] ?? '',
                $error['datos']['distrito'] ?? '',
                $error['datos']['nivel_educativo'] ?? '',
                $error['datos']['centro_poblado'] ?? '',
                $error['datos']['direccion'] ?? '',
                $error['datos']['latitud'] ?? '',
                $error['datos']['longitud'] ?? '',
                $error['datos']['radio'] ?? '',
            ];
        }
        
        return $rows;
    }

    public function headings(): array
    {
        return [
            'Fila Excel',
            'Código IE',
            'Errores',
            'codigo_modular_ie',
            'nombre',
            'distrito',
            'nivel_educativo',
            'centro_poblado',
            'direccion',
            'latitud',
            'longitud',
            'radio',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Fila de instrucciones
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                    'size' => 12,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '0066CC'],
                ],
            ],
            // Headers
            3 => [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                    'size' => 11,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'DC3545'],
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
            'B' => 15,
            'C' => 50,
            'D' => 20,
            'E' => 35,
            'F' => 18,
            'G' => 18,
            'H' => 20,
            'I' => 30,
            'J' => 12,
            'K' => 12,
            'L' => 10,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                // Merge de la fila de instrucciones
                $event->sheet->mergeCells('A1:L1');
                
                // Altura de filas
                $event->sheet->getDelegate()->getRowDimension(1)->setRowHeight(30);
                $event->sheet->getDelegate()->getRowDimension(3)->setRowHeight(25);
                
                // Wrap text en columna de errores
                $event->sheet->getDelegate()->getStyle('C:C')->getAlignment()->setWrapText(true);
                
                // Auto-filtro en los headers
                $event->sheet->getDelegate()->setAutoFilter('A3:L3');
                
                // Congelar primera fila
                $event->sheet->getDelegate()->freezePane('A4');
            },
        ];
    }
}