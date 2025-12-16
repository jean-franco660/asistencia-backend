<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUsuarioAppRequest;
use App\Http\Requests\UpdateUsuarioAppRequest;
use App\Models\Institucion;
use App\Models\UsuarioApp;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UsuarioAppController extends Controller
{
    use AuthorizesRequests;

    // =========================================================
    // LOGIN (UsuarioApp)
    // =========================================================
    public function login(Request $request)
    {
        $request->validate([
            'codigo' => 'required_without:codigo_modular_docente|string',
            'codigo_modular_docente' => 'required_without:codigo|string',
            'password' => 'required|string',
        ]);

        // Aceptar 'codigo' como alias de 'codigo_modular_docente'
        $codigo = $request->codigo_modular_docente ?? $request->codigo;
        $codigo = strtolower(trim((string) $codigo));

        $usuario = UsuarioApp::whereRaw('LOWER(codigo_modular_docente) = ?', [$codigo])
            ->with([
                'institucionesActivas:id,codigo_modular_ie,nombre,latitud,longitud,radio',
            ])
            ->first();

        if (!$usuario || !Hash::check((string) $request->password, (string) $usuario->password)) {
            return response()->json(['error' => 'Código modular o contraseña incorrectos'], 422);
        }

        if (!$usuario->activo || $usuario->estado !== 'ACTIVO') {
            return response()->json(['error' => 'Su cuenta está inactiva. Contacte al administrador.'], 403);
        }

        $token = $usuario->createToken('app-movil')->plainTextToken;

        return response()->json([
            'token' => $token,
            'usuario' => [
                'id' => $usuario->id,
                'codigo_modular_docente' => $usuario->codigo_modular_docente,
                'nombre_completo' => $usuario->nombre_completo,
                'apellido_paterno' => $usuario->apellido_paterno,
                'apellido_materno' => $usuario->apellido_materno,
                'nombres' => $usuario->nombres,
                'sexo' => $usuario->sexo,
                'cargo' => $usuario->cargo,
                'estado' => $usuario->estado,
                'instituciones' => $usuario->institucionesActivas->map(function ($i) {
                    return [
                        'id' => $i->id,
                        'codigo_modular_ie' => $i->codigo_modular_ie,
                        'nombre' => $i->nombre,
                        'nombre_display' => $i->nombre_display,
                        'latitud' => $i->latitud,
                        'longitud' => $i->longitud,
                        'radio' => $i->radio ?? 30,
                        'estado_asignacion' => $i->pivot->estado ?? null,
                        'fecha_inicio' => $i->pivot->fecha_inicio ?? null,
                        'fecha_fin' => $i->pivot->fecha_fin ?? null,
                    ];
                })->values(),
            ],
        ]);
    }

    // =========================================================
    // LISTAR USUARIOS APP (paginado)
    // =========================================================
    public function index(Request $request)
    {
        $user = $request->user();

        $query = UsuarioApp::with(['institucionesActivas:id,codigo_modular_ie,nombre']);

        // Si el usuario autenticado (web) es supervisor, filtrar por sus instituciones
        // Nota: asume que $user->instituciones devuelve Institucion (id) por pivote supervisor_institucion.
        if ($user && ($user->rol ?? null) === 'supervisor' && method_exists($user, 'instituciones')) {
            $institucionIds = $user->instituciones->pluck('id')->toArray();

            $query->whereHas('instituciones', function ($q) use ($institucionIds) {
                $q->whereIn('instituciones.id', $institucionIds);
            });
        }

        // Filtros opcionales
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('cargo')) {
            $query->where('cargo', $request->cargo);
        }

        // Filtrar por institución (N:M) usando pivote
        if ($request->filled('institucion_id')) {
            $institucionId = (int) $request->institucion_id;
            $query->whereHas('instituciones', function ($q) use ($institucionId) {
                $q->where('instituciones.id', $institucionId);
            });
        }

        if ($request->has('activo')) {
            $query->where('activo', (bool) $request->activo);
        }

        // Búsqueda
        if ($request->filled('buscar')) {
            $buscar = $request->buscar;
            $query->where(function ($q) use ($buscar) {
                $q->where('nombres', 'like', "%{$buscar}%")
                    ->orWhere('apellido_paterno', 'like', "%{$buscar}%")
                    ->orWhere('apellido_materno', 'like', "%{$buscar}%")
                    ->orWhere('codigo_modular_docente', 'like', "%{$buscar}%");
            });
        }

        $usuarios = $query->paginate(20);

        $usuarios->getCollection()->transform(function ($u) {
            return [
                'id' => $u->id,
                'codigo_modular_docente' => $u->codigo_modular_docente,
                'nombre_completo' => $u->nombre_completo,
                'sexo' => $u->sexo,
                'cargo' => $u->cargo,
                'estado' => $u->estado,
                'activo' => $u->activo,
                'instituciones' => $u->institucionesActivas->map(function ($i) {
                    return [
                        'id' => $i->id,
                        'codigo_modular_ie' => $i->codigo_modular_ie,
                        'nombre' => $i->nombre,
                        'nombre_display' => $i->nombre_display,  // Para mostrar en UI
                        'estado_asignacion' => $i->pivot->estado ?? null,
                        'fecha_inicio' => $i->pivot->fecha_inicio ?? null,
                        'fecha_fin' => $i->pivot->fecha_fin ?? null,
                    ];
                })->values(),
            ];
        });

        return response()->json($usuarios);
    }

    // =========================================================
    // CREAR USUARIO APP
    // =========================================================
    public function store(StoreUsuarioAppRequest $request)
    {
        $data = $request->validated();

        // Crear usuario app (password se hashea por mutator en el modelo)
        $usuario = UsuarioApp::create($data);

        // Asociar instituciones (N:M) por institucion_id
        // Soporta:
        // - instituciones: [{institucion_id, estado?, fecha_inicio?, fecha_fin?}]
        // - institucion_ids: [1,2,3] (legacy)
        if ($request->filled('instituciones')) {
            foreach ((array) $request->instituciones as $inst) {
                $institucionId = (int) ($inst['institucion_id'] ?? 0);
                if ($institucionId <= 0)
                    continue;

                $institucion = Institucion::find($institucionId);
                if (!$institucion)
                    continue;

                $usuario->instituciones()->syncWithoutDetaching([
                    $institucion->id => [
                        'estado' => $inst['estado'] ?? 'ACTIVO',
                        'fecha_inicio' => $inst['fecha_inicio'] ?? now(),
                        'fecha_fin' => $inst['fecha_fin'] ?? null,
                    ],
                ]);
            }
        } elseif ($request->filled('institucion_ids')) {
            foreach ((array) $request->institucion_ids as $institucionId) {
                $institucion = Institucion::find((int) $institucionId);
                if (!$institucion)
                    continue;

                $usuario->instituciones()->syncWithoutDetaching([
                    $institucion->id => [
                        'estado' => 'ACTIVO',
                        'fecha_inicio' => now(),
                        'fecha_fin' => null,
                    ],
                ]);
            }
        }

        $usuario->load(['institucionesActivas:id,codigo_modular_ie,nombre']);

        return response()->json([
            'message' => 'Usuario creado correctamente',
            'data' => [
                'id' => $usuario->id,
                'codigo_modular_docente' => $usuario->codigo_modular_docente,
                'nombre_completo' => $usuario->nombre_completo,
                'cargo' => $usuario->cargo,
                'instituciones' => $usuario->institucionesActivas->map(function ($i) {
                    return [
                        'id' => $i->id,
                        'codigo_modular_ie' => $i->codigo_modular_ie,
                        'nombre' => $i->nombre,
                        'nombre_display' => $i->nombre_display,
                        'estado_asignacion' => $i->pivot->estado ?? null,
                        'fecha_inicio' => $i->pivot->fecha_inicio ?? null,
                        'fecha_fin' => $i->pivot->fecha_fin ?? null,
                    ];
                })->values(),
            ],
        ], 201);
    }

    // =========================================================
    // MOSTRAR USUARIO APP
    // =========================================================
    public function show($id)
    {
        $usuario = UsuarioApp::with(['institucionesActivas:id,codigo_modular_ie,nombre,distrito'])
            ->find($id);

        if (!$usuario) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        return response()->json([
            'data' => [
                'id' => $usuario->id,
                'codigo_modular_docente' => $usuario->codigo_modular_docente,
                'apellido_paterno' => $usuario->apellido_paterno,
                'apellido_materno' => $usuario->apellido_materno,
                'nombres' => $usuario->nombres,
                'nombre_completo' => $usuario->nombre_completo,
                'sexo' => $usuario->sexo,
                'cargo' => $usuario->cargo,
                'estado' => $usuario->estado,
                'activo' => $usuario->activo,
                'created_at' => $usuario->created_at,
                'instituciones' => $usuario->institucionesActivas->map(function ($i) {
                    return [
                        'id' => $i->id,
                        'codigo_modular_ie' => $i->codigo_modular_ie,
                        'nombre' => $i->nombre,
                        'nombre_display' => $i->nombre_display,
                        'distrito' => $i->distrito,
                        'estado_asignacion' => $i->pivot->estado ?? null,
                        'fecha_inicio' => $i->pivot->fecha_inicio ?? null,
                        'fecha_fin' => $i->pivot->fecha_fin ?? null,
                    ];
                })->values(),
            ],
        ]);
    }

    // =========================================================
    // ACTUALIZAR USUARIO APP
    // =========================================================
    public function update(UpdateUsuarioAppRequest $request, $id)
    {
        $usuario = UsuarioApp::find($id);

        if (!$usuario) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        $data = $request->validated();

        // Si no envían password, NO se actualiza
        if (array_key_exists('password', $data) && empty($data['password'])) {
            unset($data['password']);
        }

        $usuario->update($data);

        // Actualizar instituciones si se envían (por institucion_id)
        if ($request->filled('instituciones')) {
            $sync = [];

            foreach ((array) $request->instituciones as $inst) {
                $institucionId = (int) ($inst['institucion_id'] ?? 0);
                if ($institucionId <= 0)
                    continue;

                $sync[$institucionId] = [
                    'estado' => $inst['estado'] ?? 'ACTIVO',
                    'fecha_inicio' => $inst['fecha_inicio'] ?? now(),
                    'fecha_fin' => $inst['fecha_fin'] ?? null,
                ];
            }

            // Reemplaza la lista de instituciones asociadas por la enviada
            $usuario->instituciones()->sync($sync);
        }

        $usuario->load(['institucionesActivas:id,codigo_modular_ie,nombre']);

        return response()->json([
            'message' => 'Usuario actualizado correctamente',
            'data' => [
                'id' => $usuario->id,
                'codigo_modular_docente' => $usuario->codigo_modular_docente,
                'nombre_completo' => $usuario->nombre_completo,
                'cargo' => $usuario->cargo,
                'estado' => $usuario->estado,
                'activo' => $usuario->activo,
                'instituciones' => $usuario->institucionesActivas->map(function ($i) {
                    return [
                        'id' => $i->id,
                        'codigo_modular_ie' => $i->codigo_modular_ie,
                        'nombre' => $i->nombre,
                        'nombre_display' => $i->nombre_display,
                        'estado_asignacion' => $i->pivot->estado ?? null,
                        'fecha_inicio' => $i->pivot->fecha_inicio ?? null,
                        'fecha_fin' => $i->pivot->fecha_fin ?? null,
                    ];
                })->values(),
            ],
        ]);
    }

    // =========================================================
    // ELIMINAR USUARIO APP
    // =========================================================
    public function destroy($id)
    {
        $usuario = UsuarioApp::find($id);

        if (!$usuario) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        $usuario->delete();

        return response()->json(['message' => 'Usuario eliminado correctamente']);
    }

    // =========================================================
    // ELIMINAR MÚLTIPLES USUARIOS APP
    // =========================================================
    public function destroyMultiple(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:usuarios_app,id',
        ]);

        $user = $request->user();
        $ids = $request->input('ids');

        $eliminados = 0;
        $errores = [];

        foreach ($ids as $id) {
            try {
                $usuario = UsuarioApp::findOrFail($id);

                // Verificar permisos (solo admin y super_admin pueden eliminar)
                if (!in_array($user->rol, ['administrador', 'super_admin'])) {
                    $errores[] = [
                        'id' => $id,
                        'error' => 'No tienes permisos para eliminar docentes',
                    ];
                    continue;
                }

                $usuario->delete();
                $eliminados++;

            } catch (\Exception $e) {
                $errores[] = [
                    'id' => $id,
                    'error' => 'Error al eliminar: ' . $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Se eliminaron {$eliminados} de " . count($ids) . " docente(s)",
            'eliminados' => $eliminados,
            'total' => count($ids),
            'errores' => $errores,
        ]);
    }

    // =========================================================
    // CAMBIAR ACTIVO (activar/desactivar)
    // =========================================================
    public function cambiarEstado(Request $request, $id)
    {
        $request->validate([
            'activo' => 'required|boolean',
        ]);

        $usuario = UsuarioApp::find($id);

        if (!$usuario) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        $usuario->update(['activo' => (bool) $request->activo]);

        return response()->json([
            'message' => $request->activo ? 'Usuario activado correctamente' : 'Usuario desactivado correctamente',
            'data' => [
                'id' => $usuario->id,
                'nombre_completo' => $usuario->nombre_completo,
                'activo' => $usuario->activo,
            ],
        ]);
    }

    // =========================================================
    // PERFIL DEL USUARIO AUTENTICADO
    // =========================================================
    public function perfil(Request $request)
    {
        $usuario = $request->user();

        $usuario->load(['institucionesActivas:id,codigo_modular_ie,nombre,latitud,longitud,radio']);

        return response()->json([
            'data' => [
                'id' => $usuario->id,
                'codigo_modular_docente' => $usuario->codigo_modular_docente,
                'nombre_completo' => $usuario->nombre_completo,
                'sexo' => $usuario->sexo,
                'cargo' => $usuario->cargo,
                'estado' => $usuario->estado,
                'activo' => $usuario->activo,
                'instituciones' => $usuario->institucionesActivas->map(function ($i) {
                    return [
                        'id' => $i->id,
                        'codigo_modular_ie' => $i->codigo_modular_ie,
                        'nombre' => $i->nombre,
                        'nombre_display' => $i->nombre_display,
                        'latitud' => $i->latitud,
                        'longitud' => $i->longitud,
                        'radio' => $i->radio,
                        'estado_asignacion' => $i->pivot->estado ?? null,
                        'fecha_inicio' => $i->pivot->fecha_inicio ?? null,
                        'fecha_fin' => $i->pivot->fecha_fin ?? null,
                    ];
                })->values(),
            ],
        ]);
    }

    // =========================================================
    // LOGOUT
    // =========================================================
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }
}
