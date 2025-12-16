<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class InstitucionesTemplateExport implements FromArray, WithHeadings, WithStyles, WithColumnWidths
{
    /**
     * @return array
     */
    public function array(): array
    {
        // Ejemplos de filas con datos de muestra
        return [
            [
                '1234567',
                'IE José Carlos Mariátegui',
                'Av. Principal 123',
                'Lima',
                'Secundaria',
                'San Juan',
                '-12.0464',
                '-77.0428',
                '100'
            ],
            [
                '7654321',
                'IE Túpac Amaru',
                'Jr. Los Andes 456',
                'Callao',
                'Primaria',
                'Bellavista',
                '-12.0544',
                '-77.1128',
                '150'
            ],
            [
                '0123456',
                'IE Ricardo Palma',
                'Calle Las Flores 789',
                'Lima',
                'Secundaria',
                '',
                '-12.0664',
                '-77.0528',
                '100'
            ],
        ];
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return [
            'codigo_modular_ie',
            'nombre',
            'direccion',
            'distrito',
            'nivel_educativo',
            'centro_poblado',
            'latitud',
            'longitud',
            'radio'
        ];
    }

    /**
     * Estilos para el Excel
     *
     * @param Worksheet $sheet
     * @return array
     */
    public function styles(Worksheet $sheet)
    {
        return [
            // Estilo para la fila de encabezados
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                    'size' => 12,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '70AD47'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }

    /**
     * Ancho de columnas
     *
     * @return array
     */
    public function columnWidths(): array
    {
        return [
            'A' => 20, // codigo_modular_ie
            'B' => 35, // nombre
            'C' => 30, // direccion
            'D' => 18, // distrito
            'E' => 18, // nivel_educativo
            'F' => 20, // centro_poblado
            'G' => 12, // latitud
            'H' => 12, // longitud
            'I' => 10, // radio
        ];
    }
}
