<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DirectorDashboardController extends Controller
{
   public function index(Request $request)
{
    $director = $request->user();
    $institucion = $director->institucionActual();

    if (!$institucion) {
        return response()->json([
            'institucion' => null,
            'docentes' => 0,
            'asistencias_hoy' => 0
        ]);
    }

    return response()->json([
        'institucion' => $institucion->nombre,
        'docentes' => $institucion->docentes()->count(),
        'asistencias_hoy' => $institucion->asistencias()
            ->whereDate('fecha_Hora', today())
            ->count()
    ]);
}
}
