<?php
// app/Exports/DocentesErroresExport.php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class DocentesErroresExport implements FromArray, WithHeadings, WithStyles, WithColumnWidths
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
                $error['datos']['codigo_modular_docente'] ?? '',
                $error['datos']['apellido_paterno'] ?? '',
                $error['datos']['apellido_materno'] ?? '',
                $error['datos']['nombres'] ?? '',
                $error['datos']['sexo'] ?? '',
                $error['datos']['cargo'] ?? '',
                $error['datos']['password'] ?? '',
                $error['datos']['codigo_modular_ie'] ?? '',
            ];
        }
        
        return $rows;
    }

    public function headings(): array
    {
        return [
            'Fila Excel',
            'Código Docente',
            'Errores',
            'codigo_modular_docente',
            'apellido_paterno',
            'apellido_materno',
            'nombres',
            'sexo',
            'cargo',
            'password',
            'codigo_modular_ie',
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
            'B' => 18, // Código Docente
            'C' => 50, // Errores
            'D' => 25, // codigo_modular_docente
            'E' => 20, // apellido_paterno
            'F' => 20, // apellido_materno
            'G' => 25, // nombres
            'H' => 12, // sexo
            'I' => 18, // cargo
            'J' => 15, // password
            'K' => 20, // codigo_modular_ie
        ];
    }
}