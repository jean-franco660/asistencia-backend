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

class UsuariosAppTemplateExport implements
    FromArray,
    WithHeadings,
    WithStyles,
    WithColumnWidths,
    WithEvents  // ⭐ NUEVO
{
    public function array(): array
    {
        return [
            ['DOC001', '12345678', 'GARCÍA', 'PÉREZ', 'JUAN CARLOS', 'M', '987654321', 'juan123', '0123456', 'DOCENTE'],
            ['DOC002', '87654321', 'RODRÍGUEZ', 'LÓPEZ', 'MARÍA ELENA', 'F', '912345678', 'maria123', '0123456', 'DIRECTOR'],
            ['DOC003', '11223344', 'MARTÍNEZ', 'SÁNCHEZ', 'PEDRO JOSÉ', 'M', '998877665', 'pedro123', '0123457', 'DOCENTE'],
        ];
    }

    public function headings(): array
    {
        return [
            'codigo_modular',
            'dni',
            'apellido_paterno',
            'apellido_materno',
            'nombres',
            'sexo',
            'telefono',
            'password',
            'codigo_modular_ie',
            'cargo',
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
            'A' => 20,  // codigo_modular
            'B' => 12,  // dni
            'C' => 20,  // apellido_paterno
            'D' => 20,  // apellido_materno
            'E' => 25,  // nombres
            'F' => 12,  // sexo
            'G' => 15,  // telefono
            'H' => 18,  // password
            'I' => 20,  // codigo_modular_ie
            'J' => 18,  // cargo
        ];
    }

    // ⭐ NUEVO: Agregar instrucciones y validaciones
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Altura de la fila de encabezado
                $sheet->getRowDimension(1)->setRowHeight(25);

                // Auto-filtro en encabezados
                $sheet->setAutoFilter('A1:J1');

                // Congelar primera fila
                $sheet->freezePane('A2');

                // ⭐ NUEVO: Agregar comentarios/notas en encabezados
                $sheet->getComment('A1')->getText()->createTextRun(
                    "Código único del usuario. Ejemplo: DOC001, DIR001"
                );

                $sheet->getComment('B1')->getText()->createTextRun(
                    "OBLIGATORIO. DNI del usuario (8 dígitos). Debe ser único."
                );

                $sheet->getComment('F1')->getText()->createTextRun(
                    "Valores permitidos:\n- Masculino\n- Femenino\n- M\n- F"
                );

                $sheet->getComment('G1')->getText()->createTextRun(
                    "Opcional. Teléfono/celular del usuario."
                );

                $sheet->getComment('H1')->getText()->createTextRun(
                    "Contraseña en texto plano (se hashea automáticamente)"
                );

                $sheet->getComment('I1')->getText()->createTextRun(
                    "Código de la institución. IMPORTANTE: La institución debe existir previamente en el sistema."
                );

                $sheet->getComment('J1')->getText()->createTextRun(
                    "Ejemplos: DOCENTE, DIRECTOR, COORDINADOR, AUXILIAR"
                );



                // ⭐ NUEVO: Agregar fila de instrucciones en la parte superior
                $sheet->insertNewRowBefore(1, 2);

                // Instrucciones - Fila 1
                $sheet->setCellValue('A1', ' PLANTILLA DE IMPORTACIÓN - USUARIOS APP (DOCENTES)');
                $sheet->mergeCells('A1:J1');
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
                $sheet->setCellValue('A2', '️ IMPORTANTE: Campos obligatorios: codigo_modular (único), dni (único), apellido_paterno, nombres, password, codigo_modular_ie (debe existir), cargo. Las fechas de vigencia se gestionan automáticamente.');
                $sheet->mergeCells('A2:J2');
                $sheet->getStyle('A2')->applyFromArray([
                    'font' => [
                        'italic' => true,
                        'size' => 10,
                        'color' => ['rgb' => '666666'],
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                // Ajustar alturas
                $sheet->getRowDimension(1)->setRowHeight(30);
                $sheet->getRowDimension(2)->setRowHeight(25);
                $sheet->getRowDimension(3)->setRowHeight(25); // Headers ahora en fila 3
    
                // Actualizar auto-filtro y freeze
                $sheet->setAutoFilter('A3:J3');
                $sheet->freezePane('A4');
            },
        ];
    }
}