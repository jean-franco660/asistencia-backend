<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Institucion;
use App\Models\UsuarioWeb;
use App\Http\Requests\StoreInstitucionRequest;
use App\Http\Requests\UpdateInstitucionRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;

class InstitucionController extends Controller
{
    use AuthorizesRequests;

    /**
     * Listar instituciones según el rol del usuario.
     * - super_admin y administrador: ven todas
     * - supervisor: solo las asignadas
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($this->esAdministrador($user)) {
            $instituciones = Institucion::withCount(['docentes', 'supervisores'])->get();
        } else {
            // Supervisor: solo sus instituciones
            $instituciones = $user->instituciones()
                ->withCount(['docentes', 'supervisores'])
                ->get();
        }

        return response()->json(['data' => $instituciones]);
    }

    /**
     * Crear una nueva institución.
     * Solo super_admin y administrador pueden crear.
     */
    public function store(StoreInstitucionRequest $request)
    {
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

    /**
     * Listar las instituciones asignadas al supervisor actual.
     * Este endpoint es específico para supervisores.
     */
    public function mias(Request $request)
    {
        $user = $request->user();

        // Si es administrador, retornar todas las instituciones
        if ($this->esAdministrador($user)) {
            $instituciones = Institucion::with('supervisores')->get();
        } else {
            // Supervisor: solo sus instituciones
            $instituciones = Institucion::whereHas('supervisores', function ($query) use ($user) {
                $query->where('usuarios_web.id', $user->id);
            })
                ->with('supervisores')
                ->get();
        }

        return response()->json([
            'data' => $instituciones,
        ]);
    }

    /**
     * Mostrar una institución específica.
     */
    public function show(Request $request, $id)
    {
        $institucion = Institucion::findOrFail($id);

        $this->authorize('view', $institucion);

        return response()->json(['data' => $institucion]);
    }

    /**
     * Actualizar una institución existente.
     */
    public function update(UpdateInstitucionRequest $request, $id)
    {
        $institucion = Institucion::findOrFail($id);

        $this->authorize('update', $institucion);

        $data = $request->validated();

        // Flag para eliminar logo sin subir uno nuevo
        $removeLogo = $request->boolean('remove_logo');

        if ($removeLogo && $institucion->logo) {
            // Borrar archivo físico
            Storage::disk('public')->delete($institucion->logo);
            // Dejar el campo en null
            $data['logo'] = null;
        }

        // Manejar actualización de logo (nuevo archivo)
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

    /**
     * Eliminar una institución.
     */
    public function destroy(Request $request, $id)
    {
        $institucion = Institucion::findOrFail($id);

        $this->authorize('delete', $institucion);

        // Eliminar logo si existe
        if ($institucion->logo) {
            Storage::disk('public')->delete($institucion->logo);
        }

        $institucion->delete();

        return response()->json(['message' => 'Institución eliminada correctamente']);
    }

    /**
     * Eliminar múltiples instituciones.
     * Solo super_admin y administrador pueden eliminar múltiples instituciones.
     */
    public function destroyMultiple(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:instituciones,id',
        ]);

        $user = $request->user();
        $ids = $request->input('ids');

        $eliminadas = 0;
        $errores = [];

        foreach ($ids as $id) {
            try {
                $institucion = Institucion::findOrFail($id);

                // Verificar permisos para cada institución
                $this->authorize('delete', $institucion);

                // Eliminar logo si existe
                if ($institucion->logo) {
                    Storage::disk('public')->delete($institucion->logo);
                }

                $institucion->delete();
                $eliminadas++;

            } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                $errores[] = [
                    'id' => $id,
                    'error' => 'No tienes permisos para eliminar esta institución',
                ];
            } catch (\Exception $e) {
                $errores[] = [
                    'id' => $id,
                    'error' => 'Error al eliminar: ' . $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => "Se eliminaron {$eliminadas} de " . count($ids) . " instituciones",
            'eliminadas' => $eliminadas,
            'total' => count($ids),
            'errores' => $errores,
        ]);
    }

    /**
     * Listar instituciones del usuario actual según su rol.
     * - super_admin y administrador: todas las instituciones
     * - supervisor: solo las asignadas
     */
    public function misInstituciones(Request $request)
    {
        $user = $request->user();

        if ($this->esAdministrador($user)) {
            $instituciones = Institucion::withCount(['docentes', 'supervisores'])->get();
        } else {
            // Supervisor: solo sus instituciones
            $instituciones = $user->instituciones()
                ->withCount(['docentes', 'supervisores'])
                ->get();
        }

        return response()->json(['data' => $instituciones]);
    }

    /**
     * Helper: Verifica si el usuario es super_admin o administrador.
     */
    private function esAdministrador(UsuarioWeb $user): bool
    {
        return in_array($user->rol, [
            UsuarioWeb::ROL_SUPER_ADMIN,
            UsuarioWeb::ROL_ADMIN
        ]);
    }
}