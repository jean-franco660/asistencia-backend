<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Institucion;
use App\Http\Requests\StoreInstitucionRequest;
use App\Http\Requests\UpdateInstitucionRequest;

class InstitucionController extends Controller
{
    public function index(Request $request)
    {
        // admin = ve todas / director = solo las que tiene asignadas
        if ($request->user()->rol === 'director') {
            $instituciones = $request->user()
                ->instituciones()
                ->withCount(['docentes','directores'])
                ->get();
        } else {
            $instituciones = Institucion::withCount(['docentes','directores'])->get();
        }

        return response()->json(['data' => $instituciones]);
    }

    public function store(StoreInstitucionRequest $request)
    {
        // solo admin
        $this->authorize('create', Institucion::class);

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

        $this->authorize('view', $institucion);

        return response()->json(['data' => $institucion]);
    }

    public function update(UpdateInstitucionRequest $request, $id)
    {
        $institucion = Institucion::find($id);
        if (!$institucion) {
            return response()->json(['message' => 'Institución no encontrada'], 404);
        }

        $this->authorize('update', $institucion);

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

        $this->authorize('delete', $institucion);

        $institucion->delete();

        return response()->json(['message' => 'Institución eliminada correctamente']);
    }

    public function misInstituciones(Request $request)
    {
        $user = $request->user();

        // Admin ve todas
        if ($user->rol === 'admin') {
            $instituciones = Institucion::withCount(['docentes','directores'])->get();
        } else {
            // Director solo sus instituciones
            $instituciones = $user->instituciones()
                ->withCount(['docentes','directores'])
                ->get();
        }

        return response()->json(['data' => $instituciones]);
    }

}
