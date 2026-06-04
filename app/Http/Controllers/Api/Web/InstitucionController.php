<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\Institucion;
use App\Models\UsuarioWeb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Gestiona el catálogo de instituciones educativas.
 *
 * Accesible para administradores y super_admin. Provee operaciones CRUD completas,
 * importación masiva, eliminación individual y múltiple. Los supervisores pueden
 * consultar sus instituciones asignadas mediante el método misInstituciones.
 * El logo se almacena en el disco público; al actualizar o eliminar se gestiona
 * automáticamente el archivo anterior.
 */
class InstitucionController extends Controller
{
    /**
     * Lista instituciones con filtros opcionales y soporte para ordenamiento dinámico.
     *
     * Filtros disponibles: search (nombre o código modular), nivel_educativo, distrito.
     * Si se proporciona 'limit', retorna una lista simple sin paginación. De lo contrario,
     * pagina los resultados e incluye conteos de usuarios y horarios.
     * Las columnas de ordenamiento permitidas son: id, codigo_modular_ie, nombre,
     * distrito y created_at.
     */
    public function index(Request $request)
    {
        $query = Institucion::query();

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
            return response()->json([
                'success' => true,
                'data' => $instituciones
            ]);
        }

        // Ordenamiento dinámico
        $sortBy = $request->input('sort_by', 'id');  // Por defecto: id (orden de importación)
        $sortOrder = $request->input('sort_order', 'asc');  // asc o desc

        // Validar columnas permitidas para ordenar
        $allowedSortColumns = ['id', 'codigo_modular_ie', 'nombre', 'distrito', 'created_at'];
        if (!in_array($sortBy, $allowedSortColumns)) {
            $sortBy = 'id';
        }

        // Validar orden
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc']) ? strtolower($sortOrder) : 'asc';

        $instituciones = $query->withCount('usuarios', 'horarios')
            ->orderBy($sortBy, $sortOrder)
            ->paginate($perPage);

        return response()->json($instituciones);
    }

    /**
     * Crea una nueva institución con sus datos geográficos y de ubicación.
     *
     * El campo codigo_modular_ie debe ser único. El radio define el perímetro en metros
     * para la validación de geolocalizón al marcar asistencia. Si se adjunta logo,
     * se almacena en el disco público bajo la carpeta 'logos'.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'codigo_modular_ie' => 'required|string|unique:instituciones,codigo_modular_ie',
            'nombre' => 'required|string|max:255',
            'nivel_educativo' => 'nullable|string|max:100',
            'tipo_gestion' => 'nullable|string|max:100',
            'departamento' => 'nullable|string|max:100',
            'provincia' => 'nullable|string|max:100',
            'distrito' => 'nullable|string|max:100',
            'centro_poblado' => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:500',
            'latitud' => 'required|numeric|between:-90,90',
            'longitud' => 'required|numeric|between:-180,180',
            'radio' => 'required|integer|min:10|max:1000',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,gif,svg|max:2048',
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
     * Retorna el detalle de una institución, incluyendo conteos de usuarios y horarios.
     */
    public function show($id)
    {
        $institucion = Institucion::withCount('usuarios', 'horarios')->findOrFail($id);
        return response()->json($institucion);
    }

    /**
     * Actualiza los datos de una institución, incluyendo su logo.
     *
     * Si se envía 'remove_logo' en true, elimina el archivo del disco y limpia el campo.
     * Si se adjunta un nuevo logo, elimina el anterior antes de guardar el nuevo.
     * El código modular solo es obligatorio al crear; en actualizón admite ausencia.
     */
    public function update(Request $request, $id)
    {
        $institucion = Institucion::findOrFail($id);

        $validated = $request->validate([
            'codigo_modular_ie' => 'sometimes|string|unique:instituciones,codigo_modular_ie,' . $id,
            'nombre' => 'sometimes|string|max:255',
            'nivel_educativo' => 'nullable|string|max:100',
            'tipo_gestion' => 'nullable|string|max:100',
            'departamento' => 'nullable|string|max:100',
            'provincia' => 'nullable|string|max:100',
            'distrito' => 'nullable|string|max:100',
            'centro_poblado' => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:500',
            'latitud' => 'sometimes|numeric|between:-90,90',
            'longitud' => 'sometimes|numeric|between:-180,180',
            'radio' => 'sometimes|integer|min:10|max:1000',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,gif,svg|max:2048',
            'remove_logo' => 'sometimes|boolean',
        ]);

        // Manejar eliminación de logo
        if ($request->has('remove_logo') && $request->remove_logo) {
            if ($institucion->logo && \Storage::disk('public')->exists($institucion->logo)) {
                \Storage::disk('public')->delete($institucion->logo);
            }
            $validated['logo'] = null;
        }

        // Manejar nuevo logo
        if ($request->hasFile('logo')) {
            // Eliminar logo anterior si existe
            if ($institucion->logo && \Storage::disk('public')->exists($institucion->logo)) {
                \Storage::disk('public')->delete($institucion->logo);
            }
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
     * Elimina una institución del sistema.
     *
     * Rechaza la eliminación con código 409 si existen restricciones de llave foránea
     * (usuarios, horarios, asistencias). Registra trazas de depuración en el log
     * durante el proceso para facilitar el diagnóstico de errores.
     */
    public function destroy($id)
    {
        Log::info("DELETE - Intento de eliminar institución ID: {$id}");
        Log::info("DELETE - Método HTTP: " . request()->method());
        Log::info("DELETE - Request completo: " . json_encode(request()->all()));
        Log::info("DELETE - Headers: " . json_encode(request()->headers->all()));

        try {
            $institucion = Institucion::findOrFail($id);
            Log::info("DELETE - Institución encontrada: {$institucion->nombre} (ID: {$institucion->id})");

            // Verificar si tiene relaciones que impidan eliminarla
            $tieneUsuarios = $institucion->usuarios()->count();
            $tieneHorarios = $institucion->horarios()->count();

            Log::info("DELETE - Relaciones: {$tieneUsuarios} usuarios, {$tieneHorarios} horarios");

            // Opción: Permitir eliminación forzada desvinculando relaciones
            // Descomenta estas líneas si quieres permitir eliminar instituciones con relaciones
            // $institucion->usuarios()->detach();
            // $institucion->horarios()->delete();

            $deleted = $institucion->delete();

            Log::info("DELETE - Resultado delete(): " . ($deleted ? 'TRUE' : 'FALSE'));
            Log::info("DELETE - Institución eliminada exitosamente de la DB");

            return response()->json([
                'success' => true,
                'message' => 'Institución eliminada correctamente',
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error("DELETE - Institución no encontrada con ID: {$id}");
            return response()->json([
                'success' => false,
                'message' => 'Institución no encontrada',
            ], 404);

        } catch (\Illuminate\Database\QueryException $e) {
            Log::error("DELETE - Error de base de datos: " . $e->getMessage());
            Log::error("DELETE - SQL Error Code: " . $e->getCode());

            // Error de restricción de llave foránea
            if ($e->getCode() == '23000') {
                Log::error(" DELETE - Error de restricción de llave foránea");
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar la institución porque tiene registros relacionados (usuarios, horarios, asistencias, etc.)',
                    'hint' => 'Primero elimina los registros relacionados o usa eliminación forzada.',
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => 'Error de base de datos al eliminar',
                'error' => $e->getMessage(),
            ], 500);

        } catch (\Exception $e) {
            Log::error("DELETE - Error general: " . $e->getMessage());
            Log::error("DELETE - Trace: " . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Elimina múltiples instituciones en una sola operación.
     *
     * Requiere un array de IDs válidos. Retorna el conteo de registros eliminados.
     * No verifica restricciones de llave foránea de forma individual; si alguna
     * institución tiene relaciones, la operación puede fallar en bloque.
     */
    public function destroyMultiple(Request $request)
    {
        Log::info("DELETE MULTIPLE - Intento de eliminar múltiples instituciones");
        Log::info("DELETE MULTIPLE - IDs recibidos: " . json_encode($request->ids));

        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:instituciones,id',
        ]);

        try {
            $count = Institucion::whereIn('id', $validated['ids'])->delete();

            Log::info("DELETE MULTIPLE - Eliminadas {$count} instituciones");

            return response()->json([
                'success' => true,
                'message' => "{$count} instituciones eliminadas correctamente",
                'eliminados' => $count,
            ], 200);

        } catch (\Exception $e) {
            Log::error("DELETE MULTIPLE - Error: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar instituciones: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retorna las instituciones accesibles para el usuario autenticado.
     *
     * Los administradores y super_admin ven todas las instituciones. Los supervisores
     * solo ven sus instituciones vigentes. Acepta búsqueda por los parámetros 'q'
     * o 'search', y soporte de 'limit' para retorno sin paginación.
     * Incluye conteos de usuarios de la app y horarios.
     */
    public function misInstituciones(Request $request)
    {
        $user = $request->user();

        if ($user->esSuperAdmin() || $user->esAdministrador()) {
            $query = Institucion::query();
        } else {
            // Los supervisores solo ven las instituciones a las que están asignados
            $institucionesIds = $user->institucionesVigentes()->pluck('id');
            $query = Institucion::whereIn('id', $institucionesIds);
        }

        // Aplicar filtro de búsqueda si existe
        if ($request->filled('q') || $request->filled('search')) {
            $searchTerm = $request->input('q') ?? $request->input('search');

            Log::debug('Búsqueda de instituciones', [
                'search_term' => $searchTerm,
                'user_id' => $user->id,
                'user_rol' => $user->rol,
            ]);

            $query->where(function ($q) use ($searchTerm) {
                $q->where('nombre', 'like', '%' . $searchTerm . '%')
                    ->orWhere('codigo_modular_ie', 'like', '%' . $searchTerm . '%');
            });
        }

        // Aplicar withCount
        $query->withCount(['usuariosApp', 'horarios']);

        // Soporte para limit (sin paginación)
        $limit = $request->input('limit');
        if ($limit) {
            $instituciones = $query->limit($limit)->get();
        } else {
            $instituciones = $query->get();
        }

        Log::debug('Resultados de búsqueda', [
            'count' => $instituciones->count(),
            'search_term' => $request->input('search') ?? $request->input('q'),
        ]);

        return response()->json([
            'success' => true,
            'data' => $instituciones
        ]);
    }
}
