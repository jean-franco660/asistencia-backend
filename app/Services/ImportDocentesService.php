<?php
namespace App\Services;

use App\Models\UsuarioApp;
use App\Models\Institucion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ImportDocentesService
{
    public function procesarArchivo($archivo)
    {
        // 1. VALIDAR ARCHIVO
        validator(['archivo' => $archivo], [
            'archivo' => 'required|file|mimes:xlsx,xls,csv|max:5120' // 5MB max
        ])->validate();

        // 2. LEER ARCHIVO
        $coleccion = Excel::toCollection(null, $archivo)->first();
        
        if (!$coleccion || $coleccion->isEmpty()) {
            throw new \Exception('El archivo está vacío o no se pudo leer.');
        }

        // 3. LIMITAR FILAS (protección contra archivos enormes)
        if ($coleccion->count() > 500) {
            throw new \Exception('El archivo no puede tener más de 500 registros.');
        }

        // 4. PROCESAR CON REPORTE
        $procesados = 0;
        $errores = [];

        foreach ($coleccion as $index => $fila) {
            try {
                // Validar campos requeridos
                if (empty($fila['nombre']) || 
                    empty($fila['codigo']) || 
                    empty($fila['password']) || 
                    empty($fila['institucion'])) {
                    continue; // Saltar fila vacía
                }

                DB::transaction(function () use ($fila, &$procesados) {
                    // SANITIZAR DATOS (crítico para datos externos)
                    $nombre = strip_tags(trim($fila['nombre']));
                    $codigo = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($fila['codigo']));
                    $institucionNombre = strip_tags(trim($fila['institucion']));

                    // Validar longitud mínima
                    if (strlen($nombre) < 2 || strlen($codigo) < 3) {
                        throw new \Exception('Datos inválidos');
                    }

                    // Buscar o crear la institución
                    $institucion = Institucion::firstOrCreate([
                        'nombre' => $institucionNombre,
                    ]);

                    // Buscar o crear el docente
                    $docente = UsuarioApp::firstOrCreate(
                        ['codigo' => $codigo],
                        [
                            'nombre' => $nombre,
                            'password' => Hash::make($fila['password']),
                        ]
                    );

                    // Asociar docente con institución
                    if (!$institucion->docentes()->where('usuario_app_id', $docente->id)->exists()) {
                        $institucion->docentes()->attach($docente->id);
                    }

                    $procesados++;
                });

            } catch (\Exception $e) {
                $errores[] = "Fila " . ($index + 2) . ": " . $e->getMessage();
            }
        }

        // 5. RETORNAR REPORTE
        return [
            'total' => $coleccion->count(),
            'procesados' => $procesados,
            'errores' => $errores,
            'mensaje' => $procesados > 0 
                ? "Se importaron {$procesados} docentes correctamente" 
                : "No se pudo importar ningún registro"
        ];
    }
}