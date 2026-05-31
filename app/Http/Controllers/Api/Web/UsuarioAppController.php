<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginAppRequest;
use App\Http\Requests\StoreUsuarioAppRequest;
use App\Http\Requests\UpdateUsuarioAppRequest;
use App\Models\UsuarioApp;
use App\Models\UsuarioAppInstitucion;
use App\Models\HorarioInstitucion;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class UsuarioAppController extends Controller
{
    use AuthorizesRequests;

    /**
     * Login de usuarios de la app móvil
     */
    public function login(LoginAppRequest $request): JsonResponse
    {
        // Normalizar código modular
        $codigo = strtoupper(trim($request->codigo_modular ?? $request->codigo));

        $usuario = UsuarioApp::where('codigo_modular', $codigo)->first();

        if (!$usuario || !Hash::check($request->password, $usuario->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Código modular o contraseña incorrectos'
            ], 401);
        }

        //  Validación 1: Verificar acceso habilitado
        if (!$usuario->tieneAccesoHabilitado()) {
            return response()->json([
                'success' => false,
                'message' => 'Su cuenta está deshabilitada. Contacte al administrador.'
            ], 403);
        }

        //  Validación 2: Verificar que tenga asignaciones activas
        $asignacionesActivas = $usuario->asignacionesActivas()->with(['institucion', 'horario'])->get();

        if ($asignacionesActivas->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No tiene asignaciones activas a ninguna institución. Contacte al administrador.'
            ], 403);
        }

        //  Validación 3: Verificar que al menos una asignación tenga horario
        $asignaciones = $asignacionesActivas->filter(fn($a) => $a->horario !== null);

        if ($asignaciones->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No tiene horario asignado. Contacte al administrador para que le asignen un horario.'
            ], 403);
        }

        // Revocar tokens anteriores
        $usuario->tokens()->delete();

        // Crear nuevo token
        $token = $usuario->createToken('app-movil', ['app'])->plainTextToken;

        // Retornar solo las asignaciones que tienen horario (ya filtradas arriba)
        return response()->json([
            'success' => true,
            'message' => 'Inicio de sesión exitoso',
            'token' => $token,
            'usuario' => [
                'id' => $usuario->id,
                'codigo_modular' => $usuario->codigo_modular,
                'nombre_completo' => $usuario->nombre_completo,
                'iniciales' => $usuario->iniciales,
                'sexo' => $usuario->sexo,
                'sexo_formateado' => $usuario->sexo_formateado,
                'asignaciones' => $asignaciones->map(function ($asig) {
                    return [
                        'id' => $asig->id,
                        'cargo' => $asig->cargo,
                        'institucion' => [
                            'id' => $asig->institucion->id,
                            'codigo_modular_ie' => $asig->institucion->codigo_modular_ie,
                            'nombre' => $asig->institucion->nombre,
                            'nombre_display' => $asig->institucion->nombre_display,
                            'latitud' => $asig->institucion->latitud,
                            'longitud' => $asig->institucion->longitud,
                            'radio' => $asig->institucion->radio,
                        ],
                        'horario' => $asig->horario ? [
                            'id' => $asig->horario->id,
                            'nombre_turno' => $asig->horario->nombre_turno,
                            'hora_entrada' => $asig->horario->hora_entrada_formateada,
                            'hora_salida' => $asig->horario->hora_salida_formateada,
                            'tolerancia_minutos' => $asig->horario->tolerancia_entrada_minutos,
                            'dias_laborales' => $asig->horario->dias_laborales,
                        ] : null,
                    ];
                }),
            ],
        ]);
    }

    /**
     * Lista usuarios de la app
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', UsuarioApp::class);

        $user = $request->user();
        $query = UsuarioApp::with(['asignacionesActivas.institucion']);

        // Si es supervisor, solo ver usuarios de sus instituciones
        if ($user->esSupervisor()) {
            $institucionIds = $user->getInstitucionesVigentesIds();
            $query->whereHas('asignaciones', function ($q) use ($institucionIds) {
                $q->whereIn('institucion_id', $institucionIds)
                    ->where('estado', UsuarioAppInstitucion::ESTADO_ACTIVO);
            });

            //  NUEVO: Excluir al supervisor logueado si también es usuario app
            // Esto evita que el supervisor se vea a sí mismo en la lista de docentes
            if ($user->usuario_app_id) {
                $query->where('id', '!=', $user->usuario_app_id);
            }
        }


        // Filtros
        if ($request->filled('acceso_habilitado')) {
            $query->where('acceso_habilitado', $request->boolean('acceso_habilitado'));
        }

        if ($request->filled('cargo')) {
            $query->whereHas('asignaciones', function ($q) use ($request) {
                $q->where('cargo', mb_strtoupper($request->cargo))
                    ->where('estado', UsuarioAppInstitucion::ESTADO_ACTIVO);
            });
        }

        if ($request->filled('institucion_id')) {
            $query->porInstitucion($request->institucion_id);
        }

        if ($request->filled('sexo')) {
            $query->porSexo($request->sexo);
        }

        //  Nuevo filtro por estado de asignación (ACTIVO, INACTIVO, PENDIENTE)
        if ($request->filled('estado')) {
            $query->whereHas('asignaciones', function ($q) use ($request) {
                $q->where('estado', mb_strtoupper($request->estado));
            });
        }

        if ($request->filled('search') || $request->filled('buscar')) {
            $searchTerm = $request->search ?? $request->buscar;
            $query->buscar($searchTerm);
        }

        // Ordenamiento dinámico (por defecto: ID ascendente)
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'asc');

        $allowedSortColumns = ['id', 'codigo_modular', 'apellido_paterno', 'apellido_materno', 'nombres', 'sexo', 'created_at'];
        if (!in_array($sortBy, $allowedSortColumns)) {
            $sortBy = 'id';
        }

        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc']) ? strtolower($sortOrder) : 'asc';

        $perPage = $request->input('per_page', 20);
        $usuarios = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

        // Transformar datos
        $usuarios->getCollection()->transform(function ($u) {
            return [
                'id' => $u->id,
                'codigo_modular' => $u->codigo_modular,
                'dni' => $u->dni,
                'codigo_modular_docente' => $u->codigo_modular,
                'codigo' => $u->codigo_modular,
                'apellido_paterno' => $u->apellido_paterno,
                'apellido_materno' => $u->apellido_materno,
                'nombres' => $u->nombres,
                'nombre_completo' => $u->nombre_completo,
                'iniciales' => $u->iniciales,
                'sexo' => $u->sexo,
                'sexo_formateado' => $u->sexo_formateado,
                'acceso_habilitado' => $u->acceso_habilitado,
                'activo' => $u->acceso_habilitado,
                'estado' => $u->asignaciones->first()?->estado ?? 'INACTIVO',
                'cargo' => $u->getCargoPrincipal(),
                'institucion_principal' => $u->getInstitucionPrincipal()?->nombre_display,
                'total_asignaciones' => $u->asignaciones->count(),
                'vigentes' => $u->asignaciones->filter(fn($a) => $a->estaVigente())->count(),
                'instituciones' => $u->asignaciones->map(function ($asig) {
                    return [
                        'id' => $asig->institucion->id,
                        'nombre' => $asig->institucion->nombre,
                        'nombre_display' => $asig->institucion->nombre_display,
                        'codigo_modular_ie' => $asig->institucion->codigo_modular_ie,
                        'pivot' => [
                            'id' => $asig->id,
                            'cargo' => $asig->cargo,
                            'estado' => $asig->estado,
                            'fecha_inicio' => $asig->fecha_inicio,
                            'fecha_fin' => $asig->fecha_fin,
                            'vigente' => $asig->estaVigente(),
                        ],
                    ];
                }),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $usuarios
        ]);
    }

    /**
     * Crea un nuevo usuario de la app
     *  CON ASIGNACIÓN AUTOMÁTICA DE HORARIO
     */
    public function store(StoreUsuarioAppRequest $request): JsonResponse
    {
        $this->authorize('create', UsuarioApp::class);

        DB::beginTransaction();

        try {
            // Crear usuario
            $usuario = UsuarioApp::create($request->validated());

            // Crear asignaciones
            if ($request->filled('asignaciones')) {
                foreach ($request->asignaciones as $asig) {
                    //  NUEVO: Si no se especifica horario, buscar uno automáticamente
                    $horarioId = $asig['horario_institucion_id'] ?? null;

                    if (!$horarioId) {
                        $horario = HorarioInstitucion::where('institucion_id', $asig['institucion_id'])
                            ->where('activo', true)
                            ->first();

                        if ($horario) {
                            $horarioId = $horario->id;
                            \Log::info("Horario asignado automáticamente: Usuario {$usuario->codigo_modular}, Horario {$horario->nombre_turno}");
                        }
                    }

                    UsuarioAppInstitucion::create([
                        'usuario_app_id' => $usuario->id,
                        'institucion_id' => $asig['institucion_id'],
                        'horario_institucion_id' => $horarioId, //  Asignado automáticamente
                        'cargo' => $asig['cargo'] ?? null,
                        'estado' => $asig['estado'] ?? UsuarioAppInstitucion::ESTADO_ACTIVO,
                        'fecha_inicio' => $asig['fecha_inicio'] ?? now(),
                        'fecha_fin' => $asig['fecha_fin'] ?? null,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Usuario creado correctamente',
                'data' => $usuario->load('asignacionesActivas.institucion'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al crear usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Muestra un usuario específico
     */
    public function show($id): JsonResponse
    {
        // Cargar TODAS las asignaciones (no solo activas) para ver vigencia completa
        $usuario = UsuarioApp::with(['asignaciones.institucion', 'asignaciones.horario'])
            ->findOrFail($id);

        $this->authorize('view', $usuario);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $usuario->id,
                'codigo_modular' => $usuario->codigo_modular,
                'dni' => $usuario->dni,
                'apellido_paterno' => $usuario->apellido_paterno,
                'apellido_materno' => $usuario->apellido_materno,
                'nombres' => $usuario->nombres,
                'nombre_completo' => $usuario->nombre_completo,
                'iniciales' => $usuario->iniciales,
                'sexo' => $usuario->sexo,
                'sexo_formateado' => $usuario->sexo_formateado,
                'telefono' => $usuario->telefono,
                'email' => $usuario->email,
                'acceso_habilitado' => $usuario->acceso_habilitado,
                'created_at' => $usuario->created_at,
                // Cargar TODAS las asignaciones (activas, pendientes, inactivas)
                'asignaciones' => $usuario->asignaciones->map(function ($asig) {
                    return [
                        'id' => $asig->id,
                        'cargo' => $asig->cargo,
                        'estado' => $asig->estado,
                        'fecha_inicio' => $asig->fecha_inicio,
                        'fecha_fin' => $asig->fecha_fin,
                        'vigente' => $asig->estaVigente(),
                        'institucion' => [
                            'id' => $asig->institucion->id,
                            'codigo_modular_ie' => $asig->institucion->codigo_modular_ie,
                            'nombre' => $asig->institucion->nombre,
                            'nombre_display' => $asig->institucion->nombre_display,
                            'distrito' => $asig->institucion->distrito,
                        ],
                        'horario' => $asig->horario ? [
                            'id' => $asig->horario->id,
                            'nombre_turno' => $asig->horario->nombre_turno,
                            'turno_formateado' => $asig->horario->turno_formateado,
                            'hora_entrada' => $asig->horario->hora_entrada_formateada,
                            'hora_salida' => $asig->horario->hora_salida_formateada,
                            'dias_laborales_text' => $asig->horario->dias_laborales_text,
                        ] : null,
                    ];
                }),
            ]
        ]);
    }

    /**
     * Actualiza un usuario
     */
    public function update(UpdateUsuarioAppRequest $request, $id): JsonResponse
    {
        \Log::info(" [UsuarioApp] Iniciando actualización de usuario ID: {$id}", ['request' => $request->all()]);

        $usuario = UsuarioApp::findOrFail($id);
        $this->authorize('update', $usuario);

        DB::beginTransaction();

        try {
            $data = $request->validated();

            // No actualizar password si está vacío
            if (isset($data['password']) && empty($data['password'])) {
                unset($data['password']);
            }

            $usuario->update($data);
            \Log::info(" [UsuarioApp] Datos básicos actualizados usuario ID: {$id}");

            // Actualizar asignaciones si se envían
            if ($request->has('asignaciones')) {
                \Log::info(" [UsuarioApp] Procesando asignaciones para usuario ID: {$id}", ['asignaciones' => $request->asignaciones]);

                // Desactivar asignaciones actuales
                $usuario->asignaciones()->update([
                    'estado' => UsuarioAppInstitucion::ESTADO_INACTIVO,
                    'fecha_fin' => now()
                ]);
                \Log::info("ℹ️ [UsuarioApp] Asignaciones previas marcadas como INACTIVO");

                // Crear/actualizar nuevas asignaciones
                foreach ($request->asignaciones as $index => $asig) {
                    \Log::info("️ [UsuarioApp] Procesando asignación #{$index}", ['datos' => $asig]);

                    //  NUEVO: Si no se especifica horario, buscar uno automáticamente
                    $horarioId = $asig['horario_institucion_id'] ?? null;

                    if (!$horarioId) {
                        $horario = HorarioInstitucion::where('institucion_id', $asig['institucion_id'])
                            ->where('activo', true)
                            ->first();

                        if ($horario) {
                            $horarioId = $horario->id;
                            \Log::info("ℹ️ [UsuarioApp] Horario auto-asignado: {$horario->id}");
                        }
                    }

                    //  Robustez: Buscar asignación existente (incluso eliminada/soft-deleted) para evitar duplicados
                    $asignacion = UsuarioAppInstitucion::withTrashed()
                        ->where('usuario_app_id', $usuario->id)
                        ->where('institucion_id', $asig['institucion_id'])
                        ->first();

                    if ($asignacion) {
                        \Log::info("️ [UsuarioApp] Asignación existente encontrada ID: {$asignacion->id} (Trashed: {$asignacion->trashed()})");

                        // Limpiar duplicados extra si existen (para corregir inconsistencias antiguas)
                        $duplicados = UsuarioAppInstitucion::withTrashed()
                            ->where('usuario_app_id', $usuario->id)
                            ->where('institucion_id', $asig['institucion_id'])
                            ->where('id', '!=', $asignacion->id)
                            ->get(); // Obtener para loggear antes de borrar

                        if ($duplicados->count() > 0) {
                            \Log::warning(" [UsuarioApp] Eliminando {$duplicados->count()} duplicados encontrados", ['ids' => $duplicados->pluck('id')]);

                            UsuarioAppInstitucion::withTrashed()
                                ->where('usuario_app_id', $usuario->id)
                                ->where('institucion_id', $asig['institucion_id'])
                                ->where('id', '!=', $asignacion->id)
                                ->forceDelete();
                        }

                        if ($asignacion->trashed()) {
                            $asignacion->restore();
                            \Log::info("️ [UsuarioApp] Asignación restaurada ID: {$asignacion->id}");
                        }
                    } else {
                        $asignacion = new UsuarioAppInstitucion();
                        $asignacion->usuario_app_id = $usuario->id;
                        $asignacion->institucion_id = $asig['institucion_id'];
                        \Log::info(" [UsuarioApp] Creando NUEVA asignación para Institución ID: {$asig['institucion_id']}");
                    }

                    $asignacion->fill([
                        'horario_institucion_id' => $horarioId, //  Asignado automáticamente
                        'cargo' => $asig['cargo'] ?? null,
                        'estado' => $asig['estado'] ?? UsuarioAppInstitucion::ESTADO_ACTIVO,
                        'fecha_inicio' => $asig['fecha_inicio'] ?? now(),
                        'fecha_fin' => $asig['fecha_fin'] ?? null,
                    ]);
                    $asignacion->save();
                    \Log::info(" [UsuarioApp] Asignación guardada/actualizada ID: {$asignacion->id}", ['estado' => $asignacion->estado]);
                }
            }

            DB::commit();
            \Log::info(" [UsuarioApp] Transacción completada exitosamente para usuario ID: {$id}");

            return response()->json([
                'success' => true,
                'message' => 'Usuario actualizado correctamente',
                'data' => $usuario->fresh()->load('asignacionesActivas.institucion'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error(" [UsuarioApp] Error en transacción update: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Elimina un usuario
     */
    public function destroy($id): JsonResponse
    {
        $usuario = UsuarioApp::findOrFail($id);
        $this->authorize('delete', $usuario);

        try {
            // Revocar tokens
            $usuario->tokens()->delete();

            // Eliminar usuario (las asignaciones se eliminan en cascada según migración)
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
     * Elimina múltiples usuarios
     */
    public function destroyMultiple(Request $request): JsonResponse
    {
        $this->authorize('delete', UsuarioApp::class);

        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:usuarios_app,id',
        ]);

        $eliminados = 0;
        $errores = [];

        foreach ($request->ids as $id) {
            try {
                $usuario = UsuarioApp::findOrFail($id);
                $this->authorize('delete', $usuario);

                $usuario->tokens()->delete();
                $usuario->delete();
                $eliminados++;

            } catch (\Exception $e) {
                $errores[] = [
                    'id' => $id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Se eliminaron {$eliminados} de " . count($request->ids) . " usuario(s)",
            'eliminados' => $eliminados,
            'total' => count($request->ids),
            'errores' => $errores,
        ]);
    }

    /**
     * Habilita/deshabilita acceso de un usuario
     */
    public function cambiarAcceso(Request $request, $id): JsonResponse
    {
        $usuario = UsuarioApp::findOrFail($id);
        $this->authorize('update', $usuario);

        $request->validate([
            'acceso_habilitado' => 'required|boolean',
        ]);

        try {
            if ($request->boolean('acceso_habilitado')) {
                $usuario->habilitarAcceso();
                $mensaje = 'Acceso habilitado correctamente';
            } else {
                $usuario->deshabilitarAcceso();
                $mensaje = 'Acceso deshabilitado correctamente';
            }

            return response()->json([
                'success' => true,
                'message' => $mensaje,
                'data' => [
                    'id' => $usuario->id,
                    'nombre_completo' => $usuario->nombre_completo,
                    'acceso_habilitado' => $usuario->acceso_habilitado,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar acceso: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Asigna horario a un usuario y activa su asignación
     */
    public function asignarHorario(Request $request, $id): JsonResponse
    {
        $usuario = UsuarioApp::findOrFail($id);
        $this->authorize('update', $usuario);

        $request->validate([
            'institucion_id' => 'required|exists:instituciones,id',
            'horario_institucion_id' => 'required|exists:horarios_institucion,id'
        ]);

        try {
            // Buscar asignación
            $asignacion = UsuarioAppInstitucion::where('usuario_app_id', $id)
                ->where('institucion_id', $request->institucion_id)
                ->firstOrFail();

            // Verificar que el horario pertenece a la institución
            $horario = HorarioInstitucion::where('id', $request->horario_institucion_id)
                ->where('institucion_id', $request->institucion_id)
                ->firstOrFail();

            // Actualizar asignación
            $asignacion->update([
                'horario_institucion_id' => $horario->id,
                'estado' => UsuarioAppInstitucion::ESTADO_ACTIVO
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Horario asignado correctamente. El usuario ahora puede marcar asistencia.',
                'data' => $asignacion->load(['institucion', 'horario'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al asignar horario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     *  NUEVO: Asigna horarios automáticamente a usuarios que no tienen
     */
    public function asignarHorariosAutomaticamente(Request $request): JsonResponse
    {
        $this->authorize('update', UsuarioApp::class);

        try {
            $asignacionesSinHorario = UsuarioAppInstitucion::whereNull('horario_institucion_id')
                ->where('estado', UsuarioAppInstitucion::ESTADO_ACTIVO)
                ->with(['usuarioApp', 'institucion'])
                ->get();

            if ($asignacionesSinHorario->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No hay asignaciones sin horario',
                    'data' => [
                        'actualizados' => 0,
                        'sin_horario_disponible' => 0,
                        'total_procesados' => 0,
                    ]
                ]);
            }

            $actualizados = 0;
            $sinHorario = 0;
            $detalles = [];

            foreach ($asignacionesSinHorario as $asignacion) {
                $horario = HorarioInstitucion::where('institucion_id', $asignacion->institucion_id)
                    ->where('activo', true)
                    ->first();

                if ($horario) {
                    $asignacion->update(['horario_institucion_id' => $horario->id]);
                    $actualizados++;

                    $detalles[] = [
                        'usuario' => $asignacion->usuarioApp->codigo_modular,
                        'nombre' => $asignacion->usuarioApp->nombre_completo,
                        'institucion' => $asignacion->institucion->nombre,
                        'horario_asignado' => $horario->nombre_turno,
                        'status' => 'actualizado'
                    ];

                    \Log::info("Horario asignado automáticamente: {$asignacion->usuarioApp->codigo_modular} → {$horario->nombre_turno}");
                } else {
                    $sinHorario++;

                    $detalles[] = [
                        'usuario' => $asignacion->usuarioApp->codigo_modular,
                        'nombre' => $asignacion->usuarioApp->nombre_completo,
                        'institucion' => $asignacion->institucion->nombre,
                        'horario_asignado' => null,
                        'status' => 'sin_horario_disponible'
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Proceso completado: {$actualizados} horarios asignados, {$sinHorario} sin horario disponible",
                'data' => [
                    'actualizados' => $actualizados,
                    'sin_horario_disponible' => $sinHorario,
                    'total_procesados' => $asignacionesSinHorario->count(),
                    'detalles' => $detalles,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al asignar horarios: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Perfil del usuario autenticado
     */
    public function perfil(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $usuario->load(['asignacionesActivas.institucion', 'asignacionesActivas.horario']);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $usuario->id,
                'codigo_modular' => $usuario->codigo_modular,
                'nombre_completo' => $usuario->nombre_completo,
                'iniciales' => $usuario->iniciales,
                'sexo' => $usuario->sexo,
                'sexo_formateado' => $usuario->sexo_formateado,
                'acceso_habilitado' => $usuario->acceso_habilitado,
                'asignaciones' => $usuario->asignacionesActivas->map(function ($asig) {
                    return [
                        'cargo' => $asig->cargo,
                        'vigente' => $asig->estaVigente(),
                        'institucion' => [
                            'id' => $asig->institucion->id,
                            'codigo_modular_ie' => $asig->institucion->codigo_modular_ie,
                            'nombre' => $asig->institucion->nombre,
                            'nombre_display' => $asig->institucion->nombre_display,
                            'latitud' => $asig->institucion->latitud,
                            'longitud' => $asig->institucion->longitud,
                            'radio' => $asig->institucion->radio,
                        ],
                        'horario' => $asig->horario ? [
                            'nombre_turno' => $asig->horario->nombre_turno,
                            'turno_formateado' => $asig->horario->turno_formateado,
                            'hora_entrada' => $asig->horario->hora_entrada_formateada,
                            'hora_salida' => $asig->horario->hora_salida_formateada,
                            'tolerancia_minutos' => $asig->horario->tolerancia_entrada_minutos,
                            'dias_laborales' => $asig->horario->dias_laborales,
                            'dias_laborales_text' => $asig->horario->dias_laborales_text,
                        ] : null,
                    ];
                }),
            ],
        ]);
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