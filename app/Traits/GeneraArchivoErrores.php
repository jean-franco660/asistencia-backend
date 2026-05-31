<?php

namespace App\Traits;

use App\Exports\InstitucionesErroresExport;
use App\Exports\UsuariosAppErroresExport;
use App\Models\ImportacionLog;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

trait GeneraArchivoErrores
{
    /**
     * Genera archivo Excel con los errores de una importación
     */
    protected function generarArchivoErrores(ImportacionLog $importLog): ?string
    {
        if (empty($importLog->errores_detalle)) {
            return null;
        }

        $fileName = "errores_{$importLog->tipo}_{$importLog->id}_" . 
                     now()->format('YmdHis') . '.xlsx';
        
        $filePath = "imports/errors/{$fileName}";
        
        try {
            // Transformar errores al formato esperado por los Exports
            $erroresFormateados = $this->formatearErroresParaExport($importLog);
            
            // Obtener la clase Export apropiada según el tipo
            $exportClass = $this->getExportClassParaTipo($importLog->tipo, $erroresFormateados);
            
            if (!$exportClass) {
                Log::warning("No hay clase Export definida para el tipo: {$importLog->tipo}");
                return null;
            }
            
            // Generar el archivo Excel
            Excel::store($exportClass, $filePath, 'local');
            
            Log::info(" Archivo de errores Excel generado", [
                'import_log_id' => $importLog->id,
                'tipo' => $importLog->tipo,
                'path' => $filePath,
                'errores' => count($importLog->errores_detalle),
            ]);
            
            return $filePath;
            
        } catch (\Exception $e) {
            Log::error("Error al generar archivo Excel de errores", [
                'import_log_id' => $importLog->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return null;
        }
    }

    /**
     * Transforma errores_detalle al formato esperado por los Exports
     */
    private function formatearErroresParaExport(ImportacionLog $importLog): array
    {
        $erroresFormateados = [];
        
        foreach ($importLog->errores_detalle as $error) {
            $erroresFormateados[] = match($importLog->tipo) {
                ImportacionLog::TIPO_INSTITUCIONES => $this->formatearErrorInstitucion($error),
                ImportacionLog::TIPO_USUARIOS_APP => $this->formatearErrorUsuario($error),
                default => $error, // fallback
            };
        }
        
        return $erroresFormateados;
    }

    /**
     * Formatea error de institución al formato del Export
     */
    private function formatearErrorInstitucion(array $error): array
    {
        return [
            'fila' => $error['fila'] ?? 'N/A',
            'codigo' => $error['codigo_modular_ie'] ?? 'N/A',
            'errores' => [$error['motivo'] ?? 'Error desconocido'],
            'datos' => [
                'codigo_modular_ie' => $error['codigo_modular_ie'] ?? '',
                'nombre' => $error['institucion'] ?? '',
                'distrito' => $error['distrito'] ?? '',
                'nivel_educativo' => $error['nivel_educativo'] ?? '',
                'centro_poblado' => $error['centro_poblado'] ?? '',
                'direccion' => $error['direccion'] ?? '',
                'latitud' => $error['latitud'] ?? '',
                'longitud' => $error['longitud'] ?? '',
                'radio' => $error['radio'] ?? '',
            ],
        ];
    }

    /**
     * Formatea error de usuario al formato del Export
     */
    private function formatearErrorUsuario(array $error): array
    {
        return [
            'fila' => $error['fila'] ?? 'N/A',
            'codigo' => $error['codigo_docente'] ?? 'N/A',
            'errores' => [$error['motivo'] ?? 'Error desconocido'],
            'datos' => [
                'codigo_modular_docente' => $error['codigo_docente'] ?? '',
                'apellido_paterno' => $this->extraerApellido($error['docente'] ?? '', 0),
                'apellido_materno' => $this->extraerApellido($error['docente'] ?? '', 1),
                'nombres' => $this->extraerNombres($error['docente'] ?? ''),
                'sexo' => $error['sexo'] ?? '',
                'cargo' => $error['cargo'] ?? '',
                'password' => '', // No se expone por seguridad
                'codigo_modular_ie' => $error['codigo_modular_ie'] ?? '',
            ],
        ];
    }

    /**
     * Extrae apellido del formato "APELLIDO1 APELLIDO2, NOMBRES"
     */
    private function extraerApellido(string $nombreCompleto, int $index): string
    {
        if (empty($nombreCompleto)) {
            return '';
        }
        
        $partes = explode(',', $nombreCompleto);
        
        if (count($partes) < 2) {
            return '';
        }
        
        $apellidos = explode(' ', trim($partes[0]));
        
        return $apellidos[$index] ?? '';
    }

    /**
     * Extrae nombres del formato "APELLIDO1 APELLIDO2, NOMBRES"
     */
    private function extraerNombres(string $nombreCompleto): string
    {
        if (empty($nombreCompleto)) {
            return '';
        }
        
        $partes = explode(',', $nombreCompleto);
        
        return trim($partes[1] ?? '');
    }

    /**
     * Obtiene la clase Export apropiada según el tipo
     */
    private function getExportClassParaTipo(string $tipo, array $errores)
    {
        return match($tipo) {
            ImportacionLog::TIPO_INSTITUCIONES => new InstitucionesErroresExport($errores),
            ImportacionLog::TIPO_USUARIOS_APP => new UsuariosAppErroresExport($errores),
            default => null,
        };
    }
}