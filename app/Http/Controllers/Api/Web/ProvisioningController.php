<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\UsuarioApp;
use App\Models\UsuarioWeb;
use App\Models\UsuarioAppInstitucion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class ProvisioningController extends Controller
{
    /**
     * Buscar usuarios app candidatos para ser supervisores.
     */
    public function search(Request $request)
    {
        $search = trim($request->input('search'));
        $onlyActivos = $request->boolean('only_activos', false); // Por defecto busca en todos

        if (empty($search)) {
            return response()->json(['data' => []]);
        }

        $query = UsuarioApp::query()
            ->withCount([
                'instituciones' => function ($q) {
                    // Contar solo instituciones donde la asignación está ACTIVA
                    $q->where('usuario_app_institucion.estado', UsuarioAppInstitucion::ESTADO_ACTIVO);
                }
            ])
            ->where(function ($q) use ($search) {
                $q->where('codigo_modular', 'like', "%{$search}%")
                    ->orWhere('apellido_paterno', 'like', "%{$search}%")
                    ->orWhere('apellido_materno', 'like', "%{$search}%")
                    ->orWhere('nombres', 'like', "%{$search}%")
                    // Búsqueda combinada simple para nombre completo
                    ->orWhereRaw("CONCAT(apellido_paterno, ' ', apellido_materno, ' ', nombres) LIKE ?", ["%{$search}%"]);
            });

        // Opcional: filtrar solo los que tienen acceso habilitado en la app
        if ($onlyActivos) {
            $query->where('acceso_habilitado', true);
        }

        $candidatos = $query->limit(20)->get()->map(function ($usuarioApp) {
            $hasSupervisor = UsuarioWeb::where('usuario_app_id', $usuarioApp->id)->exists();

            return [
                'id' => $usuarioApp->id,
                'codigo_modular' => $usuarioApp->codigo_modular,
                'nombre_completo' => $usuarioApp->nombre_completo,
                'instituciones_count' => $usuarioApp->instituciones_count,
                'has_supervisor_web' => $hasSupervisor,
                'acceso_habilitado' => $usuarioApp->acceso_habilitado,
            ];
        });

        return response()->json(['data' => $candidatos]);
    }

    /**
     * Obtener detalle de un usuario app para previsualizar provisioning.
     */
    public function show(UsuarioApp $usuarioApp)
    {
        // Cargar instituciones con datos del pivote
        $usuarioApp->load([
            'instituciones' => function ($q) {
                $q->withPivot('estado', 'cargo', 'fecha_inicio', 'fecha_fin');
            }
        ]);

        // Verificar si ya tiene supervisor asociado
        $usuarioWeb = UsuarioWeb::where('usuario_app_id', $usuarioApp->id)->first();

        // Calcular instituciones sugeridas (las que están activas)
        $defaultInstitucionIds = $usuarioApp->instituciones
            ->filter(fn($inst) => $inst->pivot->estado === UsuarioAppInstitucion::ESTADO_ACTIVO)
            ->pluck('id')
            ->values();

        return response()->json([
            'usuario_app' => [
                'id' => $usuarioApp->id,
                'codigo_modular' => $usuarioApp->codigo_modular,
                'nombre_completo' => $usuarioApp->nombre_completo,
                'nombres' => $usuarioApp->nombres,
                'apellido_paterno' => $usuarioApp->apellido_paterno,
                'apellido_materno' => $usuarioApp->apellido_materno,
            ],
            'has_supervisor_web' => !!$usuarioWeb,
            'supervisor_web_existente' => $usuarioWeb ? [
                'id' => $usuarioWeb->id,
                'email' => $usuarioWeb->email,
                'estado' => $usuarioWeb->estado,
            ] : null,
            'instituciones' => $usuarioApp->instituciones,
            'default_institucion_ids' => $defaultInstitucionIds,
        ]);
    }

    /**
     * Crear (provisionar) un Supervisor desde un UsuarioApp.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'usuario_app_id' => [
                'required',
                'exists:usuarios_app,id',
                // Verificar que no tenga ya un usuario web asociado
                function ($attribute, $value, $fail) {
                    if (UsuarioWeb::where('usuario_app_id', $value)->exists()) {
                        $fail('El usuario de la app seleccionado ya tiene un usuario web asignado.');
                    }
                },
            ],
            'email' => ['required', 'email', 'unique:usuarios_web,email'],
            'password' => ['required', 'min:6'],
            'institucion_ids' => ['array'],
            'institucion_ids.*' => ['integer', 'exists:instituciones,id'],
        ]);

        $usuarioAppId = $validated['usuario_app_id'];
        $institucionIds = $request->input('institucion_ids', []);

        // 1. Obtener UsuarioApp para sacar nombre
        $usuarioApp = UsuarioApp::findOrFail($usuarioAppId);

        // 2. Si no se enviaron instituciones, tomar las activas por defecto
        if (!$request->has('institucion_ids')) {
            $institucionIds = $usuarioApp->instituciones()
                ->wherePivot('estado', UsuarioAppInstitucion::ESTADO_ACTIVO)
                ->pluck('instituciones.id')
                ->toArray();
        }

        // 3. Validar pertenencia: las IDs enviadas deben pertenecer al UsuarioApp
        // (Aunque sea historial o asignación inactiva, debe haber relación)
        $idsValidos = $usuarioApp->instituciones()->pluck('instituciones.id')->toArray();
        $diferencias = array_diff($institucionIds, $idsValidos);

        if (!empty($diferencias)) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => [
                    'institucion_ids' => ['Se intentó asignar instituciones que no están vinculadas al usuario de la app.']
                ]
            ], 422);
        }

        DB::beginTransaction();

        try {
            // A. Crear UsuarioWeb
            // Nota: UsuarioWeb::setPasswordAttribute se encarga del Hash::make,
            // así que pasamos el password en plano si el mutator está activo.
            // Si queremos ser explícitos y evitar doble hash problemas, verificamos el modelo.
            // Mirando el modelo UsuarioWeb: setPasswordAttribute usa Hash::make si no está vacío.

            $usuarioWeb = UsuarioWeb::create([
                'usuario_app_id' => $usuarioApp->id,
                'nombre' => $usuarioApp->nombre_completo, // Usando el accessor
                'email' => strtolower($validated['email']),
                'password' => $validated['password'], // El mutator lo hasheará
                'rol' => UsuarioWeb::ROL_SUPERVISOR,
                'estado' => UsuarioWeb::ESTADO_AUTORIZADO, // Directamente autorizado al provisionar
            ]);

            // B. Asignar instituciones (llenando pivote fecha_inicio)
            // Usamos syncWithPivotValues para asegurar que todas tengan fecha de inicio hoy
            if (!empty($institucionIds)) {
                $usuarioWeb->instituciones()->syncWithPivotValues($institucionIds, [
                    'fecha_inicio' => now()->toDateString(),
                    'fecha_fin' => null,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Supervisor provisionado correctamente',
                'usuario_web' => $usuarioWeb,
                'instituciones_asignadas' => count($institucionIds),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al provisionar supervisor',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
