<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Institucion;
use App\Http\Requests\StoreInstitucionRequest;
use App\Http\Requests\UpdateInstitucionRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;

class InstitucionController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $user = $request->user();

        // admin = ve todas / director = solo las que tiene asignadas
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
        // solo admin puede crear
        $this->authorize('create', Institucion::class);

        $data = $request->validated();

        // Manejar subida de logo
        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('logos', 'public');
        }

        $institucion = Institucion::create($data);

        return response()->json([
            'data' => $institucion,
            'message' => 'Institución creada correctamente'
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $institucion = Institucion::findOrFail($id);

        // Verifica si el usuario puede ver esta institución
        $this->authorize('view', $institucion);

        return response()->json(['data' => $institucion]);
    }

    public function update(UpdateInstitucionRequest $request, $id)
    {
        $institucion = Institucion::findOrFail($id);

        // Verifica si el usuario puede actualizar esta institución
        $this->authorize('update', $institucion);

        $data = $request->validated();

        // Manejar actualización de logo
        if ($request->hasFile('logo')) {
            // Eliminar logo anterior si existe
            if ($institucion->logo) {
                Storage::disk('public')->delete($institucion->logo);
            }
            
            $data['logo'] = $request->file('logo')->store('logos', 'public');
        }

        $institucion->update($data);

        return response()->json([
            'data' => $institucion,
            'message' => 'Institución actualizada correctamente'
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $institucion = Institucion::findOrFail($id);

        // Verifica si el usuario puede eliminar esta institución
        $this->authorize('delete', $institucion);

        // Eliminar logo si existe
        if ($institucion->logo) {
            Storage::disk('public')->delete($institucion->logo);
        }

        $institucion->delete();

        return response()->json(['message' => 'Institución eliminada correctamente']);
    }

    public function misInstituciones(Request $request)
    {
        $user = $request->user();

        // Admin ve todas
        if ($user->rol === 'admin') {
            $instituciones = Institucion::withCount(['docentes', 'directores'])->get();
        } else {
            // Director solo sus instituciones
            $instituciones = $user->instituciones()
                ->withCount(['docentes', 'directores'])
                ->get();
        }

        return response()->json(['data' => $instituciones]);
    }
}