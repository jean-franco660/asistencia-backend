<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Expone las instituciones asignadas al docente autenticado para su uso en la app móvil.
 *
 * Retorna solo los datos necesarios para que la app pueda mostrar la institución
 * y sus horarios activos, incluyendo coordenadas para la validación geográfica.
 */
class InstitucionController extends Controller
{
    /**
     * Retorna las instituciones asignadas al docente autenticado con sus horarios activos.
     *
     * Incluye las coordenadas geográficas y el radio de la institución, utilizados
     * por la app para verificar la proximidad del docente al momento de marcar asistencia.
     */
    public function index(Request $request)
    {
        $instituciones = $request->user()->instituciones()
            ->select(
                'instituciones.id',
                'instituciones.codigo_modular_ie',
                'instituciones.nombre',
                'instituciones.direccion',
                'instituciones.latitud',
                'instituciones.longitud',
                'instituciones.radio'
            )
            ->with([
                'horarios' => function ($query) {
                    $query->select(
                        'id',
                        'institucion_id',
                        'nombre_turno',
                        'hora_entrada',
                        'hora_salida',
                        'tolerancia_minutos',
                        'dias_semana',
                        'activo'
                    );
                }
            ])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $instituciones
        ]);
    }

}
