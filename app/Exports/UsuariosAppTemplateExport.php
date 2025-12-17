<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class UsuariosAppTemplateExport implements FromArray, WithHeadings, WithStyles, WithColumnWidths
{
    public function array(): array
    {
        // Ejemplos realistas con sexo normalizado
        return [
            ['DOC001', 'GARCÍA', 'PÉREZ', 'JUAN CARLOS', 'Masculino', 'DOCENTE', 'juan123', '0123456'],
            ['DOC002', 'RODRÍGUEZ', 'LÓPEZ', 'MARÍA ELENA', 'Femenino', 'DIRECTOR', 'maria123', '0123456'],
            ['DOC003', 'MARTÍNEZ', 'SÁNCHEZ', 'PEDRO JOSÉ', 'Masculino', 'DOCENTE', 'pedro123', '0123457'],
        ];
    }

    public function headings(): array
    {
        return [
            'codigo_modular_docente',
            'apellido_paterno',
            'apellido_materno',
            'nombres',
            'sexo',              // Masculino / Femenino (o M / F)
            'cargo',             // DOCENTE / DIRECTOR / COORDINADOR
            'password',          // Texto plano (se hashea automáticamente)
            'codigo_modular_ie', // Código de institución (debe existir previamente)
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Encabezado con fondo azul
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                    'size' => 12,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4472C4'],
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
            'A' => 25, // codigo_modular_docente
            'B' => 20, // apellido_paterno
            'C' => 20, // apellido_materno
            'D' => 25, // nombres
            'E' => 12, // sexo (ajustado para "Masculino"/"Femenino")
            'F' => 18, // cargo
            'G' => 18, // password
            'H' => 20, // codigo_modular_ie
        ];
    }
}