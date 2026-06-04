<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginWebRequest;
use App\Http\Requests\StoreUsuarioWebRequest;
use App\Http\Requests\UpdateUsuarioWebRequest;
use App\Models\UsuarioWeb;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;

class UsuarioWebController extends Controller
{
    use AuthorizesRequests;

    /**
     * Login de usuarios web (admin/supervisor)
     */
    public function login(LoginWebRequest $request): JsonResponse
    {
        $email = strtolower($request->email);
        $user = UsuarioWeb::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Credenciales inválidas'
            ], 401);
        }

        if ($user->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'Esta cuenta ha sido desactivada'
            ], 403);
        }

        if ($user->esSupervisor() && !$user->estaAutorizado()) {
            $mensaje = match ($user->estado) {
                UsuarioWeb::ESTADO_PENDIENTE => 'Tu cuenta aún no ha sido autorizada',
                UsuarioWeb::ESTADO_RECHAZADO => 'Tu cuenta ha sido rechazada',
                default => 'No tienes acceso al sistema',
            };

            return response()->json([
                'success' => false,
                'message' => $mensaje
            ], 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('web-token', ['web'])->plainTextToken;

        // CORRECCIÓN
        $instituciones = [];
        if ($user->esSupervisor()) {
            $instituciones = $user->institucionesVigentes()
                ->map(fn($inst) => [
                    'id' => $inst->id,
                    'nombre' => $inst->nombre,
                    'codigo_modular_ie' => $inst->codigo_modular_ie,
                ])
                ->values()
                ->toArray();
        }

        return response()->json([
            'success' => true,
            'message' => 'Inicio de sesión exitoso',
            'user' => [
                'id' => $user->id,
                'nombre' => $user->nombre,
                'email' => $user->email,
                'codigo' => $user->usuarioApp?->codigo_modular, // Código modular del usuario app
                'rol' => $user->rol,
                'estado' => $user->estado,
                'puede_gestionar_justificaciones' => $user->puedeGestionarJustificaciones(),
                'puede_importar' => $user->puedeImportar(),
                'puede_gestionar_usuarios' => $user->puedeGestionarUsuarios(),
                'puede_ver_todas_instituciones' => $user->puedeVerTodasInstituciones(),
                'instituciones' => $instituciones,
                'created_at' => $user->created_at,
            ],
            'token' => $token,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        // Cargar relación con usuario app si existe
        $user->load('usuarioApp');

        // CORRECCIÓN
        $instituciones = [];
        if ($user->esSupervisor()) {
            $instituciones = $user->institucionesVigentes()
                ->map(fn($inst) => [
                    'id' => $inst->id,
                    'nombre' => $inst->nombre,
                    'codigo_modular_ie' => $inst->codigo_modular_ie,
                ])
                ->values()
                ->toArray();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'nombre' => $user->nombre,
                'email' => $user->email,
                'codigo' => $user->usuarioApp?->codigo_modular, // Código modular del usuario app
                'rol' => $user->rol,
                'estado' => $user->estado,
                'puede_gestionar_justificaciones' => $user->puedeGestionarJustificaciones(),
                'puede_importar' => $user->puedeImportar(),
                'puede_gestionar_usuarios' => $user->puedeGestionarUsuarios(),
                'puede_ver_todas_instituciones' => $user->puedeVerTodasInstituciones(),
                'instituciones' => $instituciones,
                'created_at' => $user->created_at,
            ]
        ]);
    }
    /**
     * Lista usuarios web
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', UsuarioWeb::class);

        $actor = $request->user();
        $query = UsuarioWeb::query();

        // Filtro por rol del actor
        if ($actor->esAdministrador()) {
            // Admin solo ve supervisores
            $query->supervisores();
        } elseif (!$actor->esSuperAdmin()) {
            // Otros roles no deberían llegar aquí, pero por seguridad
            return response()->json([
                'success' => false,
                'message' => 'No autorizado'
            ], 403);
        }

        // Filtros
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('rol') && $actor->esSuperAdmin()) {
            $query->porRol($request->rol);
        }

        if ($request->filled('search')) {
            $query->buscar($request->search);
        }

        // Ordenamiento
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->input('per_page', 20);
        $usuarios = $query->with('instituciones')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $usuarios
        ]);
    }

    /**
     * Lista supervisores pendientes de autorización
     */
    public function pendientes(Request $request): JsonResponse
    {
        $this->authorize('viewAny', UsuarioWeb::class);

        $query = UsuarioWeb::supervisores()
            ->pendientes()
            ->with('instituciones')
            ->orderBy('created_at', 'asc'); // Más antiguos primero

        $pendientes = $query->get();

        return response()->json([
            'success' => true,
            'data' => $pendientes,
            'total' => $pendientes->count()
        ]);
    }

    /**
     * Crea un nuevo usuario web
     */
    public function store(StoreUsuarioWebRequest $request): JsonResponse
    {
        // DEBUG: Ver qué llega
        \Log::info('=== CREAR ADMINISTRADOR ===');
        \Log::info('Request data:', $request->except(['password', 'password_confirmation']));

        $this->authorize('create', UsuarioWeb::class);

        DB::beginTransaction();

        try {
            $rol = $request->input('rol', UsuarioWeb::ROL_SUPERVISOR);

            \Log::info('Rol determinado:', ['rol' => $rol]);

            // Determinar estado
            $estado = in_array($rol, [UsuarioWeb::ROL_SUPER_ADMIN, UsuarioWeb::ROL_ADMINISTRADOR])
                ? UsuarioWeb::ESTADO_AUTORIZADO
                : UsuarioWeb::ESTADO_PENDIENTE;

            \Log::info('Estado determinado:', ['estado' => $estado]);

            // Preparar datos
            $data = [
                'nombre' => $request->nombre,
                'email' => $request->email,
                'password' => $request->password,
                'rol' => $rol,
                'estado' => $estado,
            ];

            \Log::info('Datos a insertar:', array_merge($data, ['password' => '***']));

            // Crear usuario
            $usuario = UsuarioWeb::create($data);

            \Log::info('Usuario creado exitosamente:', ['id' => $usuario->id]);

            // Asignar instituciones si es supervisor
            if ($usuario->esSupervisor() && $request->filled('instituciones')) {
                \Log::info('Asignando instituciones...');

                $instituciones = collect($request->instituciones)->mapWithKeys(function ($inst) {
                    return [
                        $inst['id'] => [
                            'fecha_inicio' => $inst['fecha_inicio'] ?? now(),
                            'fecha_fin' => $inst['fecha_fin'] ?? null,
                        ]
                    ];
                });

                $usuario->instituciones()->sync($instituciones);
            }

            DB::commit();

            \Log::info(' Transacción completada');

            return response()->json([
                'success' => true,
                'message' => $usuario->esSupervisor()
                    ? 'Supervisor creado correctamente'
                    : 'Administrador creado correctamente',
                'data' => $usuario->fresh()->load('instituciones'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            // LOG DETALLADO DEL ERROR
            \Log::error(' ERROR AL CREAR USUARIO');
            \Log::error('Mensaje: ' . $e->getMessage());
            \Log::error('Archivo: ' . $e->getFile());
            \Log::error('Línea: ' . $e->getLine());
            \Log::error('Request: ' . json_encode($request->except(['password', 'password_confirmation'])));
            \Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Error al crear usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Muestra un usuario específico
     */
    public function show(Request $request, $id): JsonResponse
    {
        $usuario = UsuarioWeb::with('instituciones')->findOrFail($id);
        $this->authorize('view', $usuario);

        return response()->json([
            'success' => true,
            'data' => $usuario
        ]);
    }

    /**
     * Actualiza un usuario
     */
    public function update(UpdateUsuarioWebRequest $request, $id): JsonResponse
    {
        $usuario = UsuarioWeb::with('instituciones')->findOrFail($id);
        $this->authorize('update', $usuario);

        DB::beginTransaction();

        try {
            $data = $request->only(['nombre', 'email', 'estado']);

            if ($request->filled('password')) {
                $data['password'] = $request->password;
            }

            $usuario->update($data);

            // Actualizar instituciones si es supervisor
            if ($usuario->esSupervisor() && $request->has('instituciones')) {
                $instituciones = collect($request->instituciones)->mapWithKeys(function ($inst) {
                    return [
                        $inst['id'] => [
                            'fecha_inicio' => $inst['fecha_inicio'] ?? now(),
                            'fecha_fin' => $inst['fecha_fin'] ?? null,
                        ]
                    ];
                });

                $usuario->instituciones()->sync($instituciones);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Usuario actualizado correctamente',
                'data' => $usuario->fresh()->load('instituciones'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Elimina un usuario (soft delete)
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $usuario = UsuarioWeb::with('instituciones')->findOrFail($id);
        $this->authorize('delete', $usuario);

        try {
            // Revocar todos los tokens
            $usuario->tokens()->delete();

            // Soft delete (desvincula instituciones automáticamente por el evento)
            $usuario->delete();

            return response()->json([
                'success' => true,
                'message' => 'Usuario eliminado correctamente',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Autoriza un supervisor pendiente
     */
    public function autorizar(Request $request, $id): JsonResponse
    {
        $usuario = UsuarioWeb::with('instituciones')->findOrFail($id);
        $this->authorize('autorizar', $usuario);

        if (!$usuario->estaPendiente()) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden autorizar supervisores en estado pendiente'
            ], 400);
        }

        DB::beginTransaction();

        try {
            $usuario->autorizar();

            // Auditoría personalizada
            $usuario->auditarAccion(
                'autorizado',
                "Supervisor autorizado por {$request->user()->nombre}",
                [
                    'estado_anterior' => UsuarioWeb::ESTADO_PENDIENTE,
                    'estado_nuevo' => UsuarioWeb::ESTADO_AUTORIZADO,
                    'autorizado_por' => $request->user()->id,
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Supervisor autorizado correctamente',
                'data' => $usuario->fresh()->load('instituciones'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al autorizar supervisor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Rechaza un supervisor pendiente
     */
    public function rechazar(Request $request, $id): JsonResponse
    {
        $usuario = UsuarioWeb::with('instituciones')->findOrFail($id);
        $this->authorize('rechazar', $usuario);

        if (!$usuario->estaPendiente()) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden rechazar supervisores en estado pendiente'
            ], 400);
        }

        $request->validate([
            'motivo' => 'sometimes|string|max:500'
        ]);

        DB::beginTransaction();

        try {
            $usuario->rechazar();

            // Auditoría personalizada
            $usuario->auditarAccion(
                'rechazado',
                "Supervisor rechazado por {$request->user()->nombre}",
                [
                    'estado_anterior' => UsuarioWeb::ESTADO_PENDIENTE,
                    'estado_nuevo' => UsuarioWeb::ESTADO_RECHAZADO,
                    'rechazado_por' => $request->user()->id,
                    'motivo' => $request->motivo ?? 'Sin motivo especificado',
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Supervisor rechazado',
                'data' => $usuario->fresh()->load('instituciones'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al rechazar supervisor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cierra sesión
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada correctamente'
        ]);
    }
}