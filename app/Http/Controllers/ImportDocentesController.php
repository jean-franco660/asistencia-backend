<?php

namespace App\Http\Controllers;

use App\Services\ImportDocentesService;
use Illuminate\Http\Request;

class ImportDocentesController extends Controller
{
    public function importar(Request $request)
{
    try {
        $resultado = app(ImportDocentesService::class)
            ->procesarArchivo($request->file('archivo'));
        
        if (!empty($resultado['errores'])) {
            return back()->with([
                'warning' => $resultado['mensaje'],
                'errores' => $resultado['errores']
            ]);
        }
        
        return back()->with('success', $resultado['mensaje']);
        
    } catch (\Exception $e) {
        return back()->with('error', 'Error al importar: ' . $e->getMessage());
    }
}
}
