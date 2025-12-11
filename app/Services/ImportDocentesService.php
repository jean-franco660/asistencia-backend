<?php

namespace App\Services;

use App\Imports\DocentesImport;
use App\Models\UsuarioApp;
use App\Models\Institucion;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ImportDocentesService
{
    public function procesarArchivo($archivo)
    {
        // 1. VALIDAR ARCHIVO
        validator(['archivo' => $archivo], [
            'archivo' => 'required|file|mimes:xlsx,xls,csv|max:5120', // 5MB max
        ])->validate();

        // 2. LEER ARCHIVO USANDO HEADING ROW
        $coleccion = Excel::toCollection(new DocentesImport, $archivo)->first();

        if (!$coleccion || $coleccion->isEmpty()) {
            throw new \Exception('El archivo está vacío o no se pudo leer.');
        }

        // 3. LIMITAR FILAS
        if ($coleccion->count() > 500) {
            throw new \Exception('El archivo no puede tener más de 500 registros.');
        }

        $procesados = 0;
        $errores = [];

        foreach ($coleccion as $index => $fila) {
            try {
                // Obtener campos (cabeceras del Excel)
                $nombre      = $fila['nombre']      ?? null;
                $codigo      = $fila['codigo']      ?? null;
                $passwordRaw = $fila['password']    ?? null;
                $instNombre  = $fila['institucion'] ?? null;

                // Validar campos requeridos
                if (empty($nombre) || empty($codigo) || empty($passwordRaw) || empty($instNombre)) {
                    throw new \Exception('Faltan campos requeridos (nombre, código, password o institución).');
                }

                DB::transaction(function () use ($nombre, $codigo, $passwordRaw, $instNombre, &$procesados) {
                    // Sanitizar datos
                    $nombreSan  = strip_tags(trim($nombre));
                    $codigoSan  = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($codigo));
                    $instSan    = strip_tags(trim($instNombre));

                    // Validar longitud mínima
                    if (strlen($nombreSan) < 2 || strlen($codigoSan) < 3) {
                        throw new \Exception('Datos inválidos (nombre o código demasiado cortos).');
                    }

                    // Buscar o crear la institución
                    $institucion = Institucion::firstOrCreate([
                        'nombre' => $instSan,
                    ]);

                    // Crear o actualizar el docente
                    $docente = UsuarioApp::updateOrCreate(
                        ['codigo' => $codigoSan],
                        [
                            'nombre'   => $nombreSan,
                            'password' => $passwordRaw,
                            'activo'   => true,
                        ]
                    );

                    // Asociar docente con institución
                    if (!$institucion->docentes()->where('usuario_app_id', $docente->id)->exists()) {
                        $institucion->docentes()->attach($docente->id);
                    }

                    $procesados++;
                });

            } catch (\Exception $e) {
                // +2 por la fila de encabezado (fila 1) y el index 0-based
                $errores[] = "Fila " . ($index + 2) . ": " . $e->getMessage();
            }
        }

        return [
            'total'      => $coleccion->count(),
            'procesados' => $procesados,
            'errores'    => $errores,
            'mensaje'    => $procesados > 0
                ? "Se importaron {$procesados} docentes correctamente"
                : "No se pudo importar ningún registro",
        ];
    }
}
