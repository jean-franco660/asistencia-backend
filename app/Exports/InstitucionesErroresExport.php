<?php
// app/Exports/InstitucionesErroresExport.php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class InstitucionesErroresExport implements FromArray, WithHeadings, WithStyles, WithColumnWidths
{
    protected $errores;

    public function __construct(array $errores)
    {
        $this->errores = $errores;
    }

    public function array(): array
    {
        $rows = [];
        
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
            1 => [
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
            'A' => 12, // Fila
            'B' => 15, // Código IE
            'C' => 50, // Errores
            'D' => 20, // codigo_modular_ie
            'E' => 35, // nombre
            'F' => 18, // distrito
            'G' => 18, // nivel_educativo
            'H' => 20, // centro_poblado
            'I' => 30, // direccion
            'J' => 12, // latitud
            'K' => 12, // longitud
            'L' => 10, // radio
        ];
    }
}