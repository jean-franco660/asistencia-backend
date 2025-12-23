<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AppInstitucionController extends Controller
{
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
