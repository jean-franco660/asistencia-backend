<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Institucion;
use App\Http\Requests\StoreInstitucionRequest;
use App\Http\Requests\UpdateInstitucionRequest;

class WebInstitucionController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // admin = todas / director = solo asignadas
        if ($user->rol === 'director') {
            $instituciones = $user->instituciones()
                ->withCount(['docentes', 'directores'])
                ->get();
        } else {
            $instituciones = Institucion::withCount(['docentes', 'directores'])->get();
        }

        return response()->json(['data' => $instituciones]);
    }

    public function store(StoreInstitucionRequest $request)
    {
        // Autorización manual si no estás usando Policies
        if ($request->user()->rol !== 'admin') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $institucion = Institucion::create($request->validated());

        return response()->json([
            'data' => $institucion,
            'message' => 'Institución creada correctamente'
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $institucion = Institucion::find($id);
        if (!$institucion) {
            return response()->json(['message' => 'Institución no encontrada'], 404);
        }

        // Autorización manual
        if ($request->user()->rol !== 'admin' &&
            !$request->user()->instituciones->contains($institucion->id)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        return response()->json(['data' => $institucion]);
    }

    public function update(UpdateInstitucionRequest $request, $id)
    {
        $institucion = Institucion::find($id);
        if (!$institucion) {
            return response()->json(['message' => 'Institución no encontrada'], 404);
        }

        if ($request->user()->rol !== 'admin') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $institucion->update($request->validated());

        return response()->json([
            'data' => $institucion,
            'message' => 'Institución actualizada correctamente'
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $institucion = Institucion::find($id);
        if (!$institucion) {
            return response()->json(['message' => 'Institución no encontrada'], 404);
        }

        if ($request->user()->rol !== 'admin') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $institucion->delete();

        return response()->json(['message' => 'Institución eliminada correctamente']);
    }

    public function misInstituciones(Request $request)
    {
        $user = $request->user();

        if ($user->rol === 'admin') {
            $instituciones = Institucion::withCount(['docentes','directores'])->get();
        } else {
            $instituciones = $user->instituciones()
                ->withCount(['docentes','directores'])
                ->get();
        }

        return response()->json(['data' => $instituciones]);
    }

}
