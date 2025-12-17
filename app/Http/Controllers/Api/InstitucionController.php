<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Institucion;
use App\Models\UsuarioWeb;
use Illuminate\Http\Request;

class InstitucionController extends Controller
{
    /**
     * Listar instituciones
     */
    public function index(Request $request)
    {
        $query = Institucion::query();

        // Filtros
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('nombre', 'like', '%' . $request->search . '%')
                    ->orWhere('codigo_modular_ie', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('nivel_educativo')) {
            $query->where('nivel_educativo', $request->nivel_educativo);
        }

        if ($request->filled('distrito')) {
            $query->where('distrito', $request->distrito);
        }

        $perPage = $request->input('per_page', 20);
        $limit = $request->input('limit');

        if ($limit) {
            $instituciones = $query->limit($limit)->get();
            return response()->json($instituciones);
        }

        $instituciones = $query->withCount('usuarios', 'horarios')
            ->orderBy('nombre')
            ->paginate($perPage);

        return response()->json($instituciones);
    }

    /**
     * Crear institución
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'codigo_modular_ie' => 'required|string|unique:instituciones,codigo_modular_ie',
            'nombre' => 'required|string|max:255',
            'nivel_educativo' => 'nullable|string|max:100',
            'distrito' => 'nullable|string|max:100',
            'centro_poblado' => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:500',
            'latitud' => 'required|numeric|between:-90,90',
            'longitud' => 'required|numeric|between:-180,180',
            'radio' => 'required|integer|min:10|max:1000',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            $validated['logo'] = $request->file('logo')->store('logos', 'public');
        }

        $institucion = Institucion::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Institución creada correctamente',
            'data' => $institucion,
        ], 201);
    }

    /**
     * Mostrar institución
     */
    public function show($id)
    {
        $institucion = Institucion::withCount('usuarios', 'horarios')->findOrFail($id);
        return response()->json($institucion);
    }

    /**
     * Actualizar institución
     */
    public function update(Request $request, $id)
    {
        $institucion = Institucion::findOrFail($id);

        $validated = $request->validate([
            'codigo_modular_ie' => 'sometimes|string|unique:instituciones,codigo_modular_ie,' . $id,
            'nombre' => 'sometimes|string|max:255',
            'nivel_educativo' => 'nullable|string|max:100',
            'distrito' => 'nullable|string|max:100',
            'centro_poblado' => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:500',
            'latitud' => 'sometimes|numeric|between:-90,90',
            'longitud' => 'sometimes|numeric|between:-180,180',
            'radio' => 'sometimes|integer|min:10|max:1000',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            $validated['logo'] = $request->file('logo')->store('logos', 'public');
        }

        $institucion->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Institución actualizada correctamente',
            'data' => $institucion->fresh(),
        ]);
    }

    /**
     * Eliminar institución
     */
    public function destroy($id)
    {
        $institucion = Institucion::findOrFail($id);
        $institucion->delete();

        return response()->json([
            'success' => true,
            'message' => 'Institución eliminada correctamente',
        ]);
    }

    /**
     * Eliminar múltiples instituciones
     */
    public function destroyMultiple(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:instituciones,id',
        ]);

        Institucion::whereIn('id', $validated['ids'])->delete();

        return response()->json([
            'success' => true,
            'message' => count($validated['ids']) . ' instituciones eliminadas',
        ]);
    }

    /**
     * Instituciones del supervisor autenticado
     */
    public function misInstituciones(Request $request)
    {
        $user = $request->user();

        if ($user->esSuperAdmin() || $user->esAdministrador()) {
            return $this->index($request);
        }

        $instituciones = $user->institucionesVigentes()
            ->withCount('usuarios', 'horarios')
            ->get();

        return response()->json($instituciones);
    }
}