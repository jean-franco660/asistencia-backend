<?php

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

class InstitucionesTemplateExport implements
    FromArray,
    WithHeadings,
    WithStyles,
    WithColumnWidths,
    WithEvents  // ⭐ NUEVO
{
    public function array(): array
    {
        return [
            // Ejemplo completo
            ['0123456', 'IE José Carlos Mariátegui', 'San Juan de Lurigancho', 'Secundaria', 'PUBLICA', '-12.0464', '-77.0428', '50'],

            // Ejemplo con tipo_gestion PRIVADA
            ['0238931', 'IE Túpac Amaru', 'Wanchaq', 'Primaria', 'PRIVADA', '-13.5319', '-71.9675', '30'],

            // Ejemplo con campos opcionales vacíos
            ['0345678', 'IE Ricardo Palma', 'Cayma', 'Secundaria', '', '-16.4090', '-71.5375', '40'],

            // Ejemplo alfanumérico con PUBLICA_CONVENIO
            ['P210002', 'IE San Martín de Porres', 'El Porvenir', 'Inicial', 'PUBLICA_CONVENIO', '-8.1116', '-79.0288', '35'],
        ];
    }

    public function headings(): array
    {
        return [
            'codigo_modular_ie',
            'nombre',
            'distrito',
            'nivel_educativo',
            'tipo_gestion',
            'latitud',
            'longitud',
            'radio'
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
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

    public function columnWidths(): array
    {
        return [
            'A' => 20,  // codigo_modular_ie
            'B' => 35,  // nombre
            'C' => 18,  // distrito
            'D' => 18,  // nivel_educativo
            'E' => 18,  // tipo_gestion
            'F' => 12,  // latitud
            'G' => 12,  // longitud
            'H' => 10,  // radio
        ];
    }

    // ⭐ NUEVO: Agregar instrucciones y comentarios
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Altura de la fila de encabezado
                $sheet->getRowDimension(1)->setRowHeight(25);

                // Auto-filtro
                $sheet->setAutoFilter('A1:H1');

                // Congelar primera fila
                $sheet->freezePane('A2');

                // ⭐ NUEVO: Comentarios en encabezados
                $sheet->getComment('A1')->getText()->createTextRun(
                    "Código MINEDU de 7 dígitos o alfanumérico.\nEjemplos:\n- 0123456 (numérico)\n- P210002 (alfanumérico UGEL)"
                );

                $sheet->getComment('B1')->getText()->createTextRun(
                    "Nombre completo de la institución educativa"
                );

                $sheet->getComment('C1')->getText()->createTextRun(
                    "OBLIGATORIO. Distrito donde se ubica la IE."
                );

                $sheet->getComment('D1')->getText()->createTextRun(
                    "Ejemplos: Inicial, Primaria, Secundaria, Técnico"
                );

                $sheet->getComment('E1')->getText()->createTextRun(
                    "Opcional. Tipo de gestión:\n- PUBLICA\n- PRIVADA\n- PUBLICA_CONVENIO"
                );

                $sheet->getComment('F1')->getText()->createTextRun(
                    "Latitud en formato decimal.\nRango: -90 a 90\nEjemplo: -12.0464"
                );

                $sheet->getComment('G1')->getText()->createTextRun(
                    "Longitud en formato decimal.\nRango: -180 a 180\nEjemplo: -77.0428"
                );

                $sheet->getComment('H1')->getText()->createTextRun(
                    "Radio de geolocalización en metros.\nRecomendado: 10-500\nEjemplo: 50"
                );

                // ⭐ NUEVO: Agregar filas de instrucciones
                $sheet->insertNewRowBefore(1, 2);

                // Instrucciones - Fila 1
                $sheet->setCellValue('A1', '📋 PLANTILLA DE IMPORTACIÓN - INSTITUCIONES EDUCATIVAS');
                $sheet->mergeCells('A1:H1');
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'size' => 14,
                        'color' => ['rgb' => 'FFFFFF'],
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => '0066CC'],
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                ]);

                // Notas - Fila 2
                $sheet->setCellValue('A2', '⚠️ IMPORTANTE: Campos obligatorios: codigo_modular_ie (único), nombre, distrito, nivel_educativo. Pase el cursor sobre los encabezados para ver más ayuda.');
                $sheet->mergeCells('A2:H2');
                $sheet->getStyle('A2')->applyFromArray([
                    'font' => [
                        'italic' => true,
                        'size' => 10,
                        'color' => ['rgb' => '666666'],
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'wrapText' => true,
                    ],
                ]);

                // Ajustar alturas
                $sheet->getRowDimension(1)->setRowHeight(30);
                $sheet->getRowDimension(2)->setRowHeight(35);
                $sheet->getRowDimension(3)->setRowHeight(25); // Headers
    
                // Actualizar auto-filtro y freeze
                $sheet->setAutoFilter('A3:H3');
                $sheet->freezePane('A4');
            },
        ];
    }
}