<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Controladores App (Docentes - App Móvil)
use App\Http\Controllers\Api\App\AsistenciaController as AppAsistenciaController;
use App\Http\Controllers\Api\App\InstitucionController as AppInstitucionController;
use App\Http\Controllers\Api\App\ScheduleController as AppScheduleController;

// Controladores Web (Administradores/Supervisores - Panel Web)
use App\Http\Controllers\Api\Web\AsistenciaController as WebAsistenciaController;
use App\Http\Controllers\Api\Web\AuthController;
use App\Http\Controllers\Api\Web\UsuarioWebController;
use App\Http\Controllers\Api\Web\UsuarioAppController;
use App\Http\Controllers\Api\Web\UsuarioAppImportController;
use App\Http\Controllers\Api\Web\UsuarioAppInstitucionController;
use App\Http\Controllers\Api\Web\InstitucionController as WebInstitucionController;
use App\Http\Controllers\Api\Web\InstitucionImportController;
use App\Http\Controllers\Api\Web\HorariosInstitucionController;
use App\Http\Controllers\Api\Web\FeriadoController;
use App\Http\Controllers\Api\Web\StatsController;
use App\Http\Controllers\Api\Web\SupervisorDashboardController;
use App\Http\Controllers\Api\Web\AuditLogController;
use App\Http\Controllers\Api\Web\ReporteController;
use App\Http\Controllers\Api\Web\JustificacionController;
use App\Http\Controllers\Api\Web\PerfilController;
use App\Http\Controllers\Api\Web\ProvisioningController;
use App\Http\Controllers\Api\Web\ScheduleManagementController;

// Endpoint de salud para verificar que la API responde; no requiere autenticación
Route::get('/status', fn () => response()->json(['ok' => true, 'message' => 'API funcionando correctamente']));

/*
|--------------------------------------------------------------------------
| RUTAS PARA LA APP MÓVIL (DOCENTES)
|--------------------------------------------------------------------------
*/
Route::prefix('v1/app')->group(function () {
    // Login con código modular y contraseña; aplica throttle específico para login
    Route::post('/login', [UsuarioAppController::class, 'login'])->middleware('throttle:login');

    // Rutas protegidas con token
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        // Perfil
        Route::get('/perfil', [UsuarioAppController::class, 'perfil']);
        Route::post('/logout', [UsuarioAppController::class, 'logout']);

        // Instituciones del docente
        Route::get('/instituciones', [AppInstitucionController::class, 'index']);

        // Asistencias (App)
        Route::post('/asistencia', [AppAsistenciaController::class, 'store']);
        Route::get('/asistencia/{usuarioId}', [AppAsistenciaController::class, 'historial']);
        // Permite sincronizar marcaciones realizadas sin conexión desde la app móvil
        Route::post('/asistencias/sincronizar', [AppAsistenciaController::class, 'syncMovil']);
        Route::get('/estado-dia', [AppAsistenciaController::class, 'estadoDia']);

        // Horarios
        Route::get('/horarios-institucion', [HorariosInstitucionController::class, 'index']);

        // Justificaciones (Docente: crear, ver, eliminar las suyas)
        Route::get('/justificaciones', [JustificacionController::class, 'index']);
        Route::post('/justificaciones', [JustificacionController::class, 'store']);
        Route::get('/justificaciones/{id}', [JustificacionController::class, 'show']);
        Route::delete('/justificaciones/{id}', [JustificacionController::class, 'destroy']);

        // Gestión de Horarios (Auto-asignación)
        Route::get('/mis-horarios', [AppScheduleController::class, 'getMisHorarios']);
        Route::post('/actualizar-horarios', [AppScheduleController::class, 'actualizarHorarios']);
    });
});

/*
|--------------------------------------------------------------------------
| RUTAS PARA LA WEB (ADMINISTRADOR / SUPERVISOR)
|--------------------------------------------------------------------------
*/
Route::prefix('v1/web')->group(function () {
    Route::post('/login', [UsuarioWebController::class, 'login'])->middleware('throttle:login');
});

Route::prefix('v1/web')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // Dashboard
    Route::get('/supervisor/dashboard', [SupervisorDashboardController::class, 'index']);

    // Perfil
    Route::get('/me', [UsuarioWebController::class, 'me']);
    Route::post('/perfil/cambiar-password', [PerfilController::class, 'cambiarPassword']);
    Route::post('/perfil/cambiar-email', [PerfilController::class, 'cambiarEmail']);

    // Logout: invalida el token actual de Sanctum sin afectar otras sesiones activas
    Route::post('/logout', function (Request $request) {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada correctamente']);
    });

    /*
    |--------------------------------------------------------------------------
    | USUARIOS WEB (Supervisores/Administradores)
    |--------------------------------------------------------------------------
    */
    Route::prefix('usuarios-web')->group(function () {
        Route::get('/', [UsuarioWebController::class, 'index']);
        Route::get('/pendientes', [UsuarioWebController::class, 'pendientes']);
        Route::post('/', [UsuarioWebController::class, 'store']);
        Route::get('/{id}', [UsuarioWebController::class, 'show']);
        Route::put('/{id}', [UsuarioWebController::class, 'update']);
        Route::delete('/{id}', [UsuarioWebController::class, 'destroy']);

        // Autorizar/Rechazar: throttle reducido para evitar aprobaciones masivas por error
        Route::middleware('throttle:acciones-criticas')->group(function () {
            Route::post('/autorizar/{id}', [UsuarioWebController::class, 'autorizar']);
            Route::post('/rechazar/{id}', [UsuarioWebController::class, 'rechazar']);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | PROVISIONES (Crear Supervisor desde Usuario App)
    |--------------------------------------------------------------------------
    */
    Route::prefix('supervisores/provisioning')->group(function () {
        Route::get('/search', [ProvisioningController::class, 'search']);
        Route::get('/usuario-app/{usuarioApp}', [ProvisioningController::class, 'show']);
        Route::post('/', [ProvisioningController::class, 'store']);
    });

    /*
    |--------------------------------------------------------------------------
    | USUARIOS APP (Docentes)  Gestión desde el panel web
    |--------------------------------------------------------------------------
    */
    Route::prefix('usuarios-app')->group(function () {
        Route::get('/template', [UsuarioAppImportController::class, 'downloadTemplate']);
        Route::delete('/delete-multiple', [UsuarioAppController::class, 'destroyMultiple']);

        Route::get('/', [UsuarioAppController::class, 'index']);
        Route::post('/', [UsuarioAppController::class, 'store']);
        Route::get('/{id}', [UsuarioAppController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [UsuarioAppController::class, 'update'])->whereNumber('id');
        // PATCH acepta actualizaciones parciales del mismo recurso
        Route::patch('/{id}', [UsuarioAppController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [UsuarioAppController::class, 'destroy'])->whereNumber('id');
        Route::patch('/{id}/asignar-horario', [UsuarioAppController::class, 'asignarHorario'])->whereNumber('id');
        Route::patch('/{id}/estado', [UsuarioAppController::class, 'cambiarEstado'])->whereNumber('id');

        // Importación
        Route::middleware('throttle:importaciones')->group(function () {
            Route::post('/importar', [UsuarioAppImportController::class, 'import']);
            Route::get('/importacion/{id}/errores.xlsx', [UsuarioAppImportController::class, 'erroresExcel'])->whereNumber('id');
        });

        Route::get('/import/stats', [UsuarioAppImportController::class, 'stats']);
        Route::get('/importacion/{id}', [UsuarioAppImportController::class, 'estadoImportacion'])->whereNumber('id');
    });

    /*
    |--------------------------------------------------------------------------
    | ASIGNACIONES (Usuario App - Institución)
    |--------------------------------------------------------------------------
    */
    Route::post('/usuario-app-institucion/{id}/inactivar', [UsuarioAppInstitucionController::class, 'inactivar'])->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | INSTITUCIONES
    |--------------------------------------------------------------------------
    */
    Route::prefix('instituciones')->group(function () {
        Route::get('/template', [InstitucionImportController::class, 'downloadTemplate']);
        Route::get('/mias', [WebInstitucionController::class, 'misInstituciones']);
        Route::delete('/delete-multiple', [WebInstitucionController::class, 'destroyMultiple']);

        Route::middleware('throttle:importaciones')->group(function () {
            Route::post('/importar', [InstitucionImportController::class, 'import']);
            Route::get('/importacion/{id}/errores.xlsx', [InstitucionImportController::class, 'erroresExcel'])->whereNumber('id');
        });

        Route::get('/import/stats', [InstitucionImportController::class, 'stats']);
        Route::get('/importacion/{id}', [InstitucionImportController::class, 'estadoImportacion'])->whereNumber('id');

        Route::get('/', [WebInstitucionController::class, 'index']);
        Route::post('/', [WebInstitucionController::class, 'store']);
        Route::get('/{id}', [WebInstitucionController::class, 'show']);
        Route::put('/{id}', [WebInstitucionController::class, 'update']);
        Route::patch('/{id}', [WebInstitucionController::class, 'update']);
        Route::delete('/{id}', [WebInstitucionController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | HORARIOS
    |--------------------------------------------------------------------------
    */
    Route::prefix('horarios')->group(function () {
        Route::get('/', [HorariosInstitucionController::class, 'index']);
        Route::post('/', [HorariosInstitucionController::class, 'store']);
        Route::put('/{id}', [HorariosInstitucionController::class, 'update']);
        Route::delete('/{id}', [HorariosInstitucionController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | FERIADOS
    |--------------------------------------------------------------------------
    */
    Route::prefix('feriados')->group(function () {
        Route::get('/', [FeriadoController::class, 'index']);
        Route::post('/', [FeriadoController::class, 'store']);
        Route::put('/{id}', [FeriadoController::class, 'update']);
        Route::delete('/{id}', [FeriadoController::class, 'destroy']);
        Route::post('/actualizar-automatico', [FeriadoController::class, 'actualizarAutomatico']);
    });

    /*
    |--------------------------------------------------------------------------
    | ASISTENCIAS (Web)
    |--------------------------------------------------------------------------
    */
    Route::prefix('asistencias')->group(function () {
        Route::get('/cabeceras', [WebAsistenciaController::class, 'listCabeceras']);
        Route::get('/semana', [AppAsistenciaController::class, 'resumenSemanal']);
        Route::get('/mes-grafico', [AppAsistenciaController::class, 'resumenMensualGrafico']);
        Route::get('/exportar', [WebAsistenciaController::class, 'exportar'])->name('asistencias.exportar');
        Route::get('/exportar-institucion/{id}', [WebAsistenciaController::class, 'exportarInstitucion'])->whereNumber('id');

        Route::get('/marcaciones/{id}', [WebAsistenciaController::class, 'getMarcacion'])->whereNumber('id');

        Route::get('/', [WebAsistenciaController::class, 'index']);
        Route::get('/{id}', [WebAsistenciaController::class, 'show'])->whereNumber('id');

        Route::put('/marcaciones/{id}/review', [WebAsistenciaController::class, 'updateReview'])->whereNumber('id');
    });

    // Foto de asistencia
    Route::get('/asistencia/foto/{id}', [WebAsistenciaController::class, 'foto'])->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | JUSTIFICACIONES (Web  Aprobar/Rechazar)
    |--------------------------------------------------------------------------
    */
    Route::prefix('justificaciones')->group(function () {
        Route::get('/', [JustificacionController::class, 'index']);
        Route::get('/{id}', [JustificacionController::class, 'show']);
        Route::delete('/{id}', [JustificacionController::class, 'destroy']);

        // Aprobar/Rechazar: throttle reducido para evitar acciones masivas accidentales
        Route::middleware('throttle:acciones-criticas')->group(function () {
            Route::post('/{id}/aprobar', [JustificacionController::class, 'aprobar']);
            Route::post('/{id}/rechazar', [JustificacionController::class, 'rechazar']);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | ESTADÍSTICAS
    |--------------------------------------------------------------------------
    */
    Route::get('/stats', [StatsController::class, 'index']);

    /*
    |--------------------------------------------------------------------------
    | AUDITORÍA (Solo Super Admin)
    |--------------------------------------------------------------------------
    */
    Route::prefix('audit-logs')->middleware('can:viewAny,App\Models\AuditLog')->group(function () {
        Route::get('/', [AuditLogController::class, 'index']);
        Route::get('/stats', [AuditLogController::class, 'stats']);
        Route::get('/modelo/{modelo}/{id}', [AuditLogController::class, 'historialModelo']);
        Route::get('/{id}', [AuditLogController::class, 'show']);
    });

    /*
    |--------------------------------------------------------------------------
    | GESTIÓN DE HORARIOS
    |--------------------------------------------------------------------------
    */
    Route::prefix('horarios-gestion')->group(function () {
        Route::get('/asignaciones', [ScheduleManagementController::class, 'index']);
        Route::get('/historial', [ScheduleManagementController::class, 'historial']);
        Route::post('/modificar', [ScheduleManagementController::class, 'modificarHorarios']);
    });

    /*
    |--------------------------------------------------------------------------
    | REPORTES AVANZADOS (Excel)
    |--------------------------------------------------------------------------
    */
    Route::prefix('reportes')->group(function () {
        Route::get('/periodo', [ReporteController::class, 'periodo']);
        Route::get('/docente-historial', [ReporteController::class, 'docenteHistorial']);
        Route::get('/institucion-consolidado', [ReporteController::class, 'institucionConsolidado']);
        Route::get('/mensual', [ReporteController::class, 'mensual']);
    });
});

// Sirve los logos de instituciones desde el disco público; accesible sin autenticación
// El patrón '.*' permite nombres de archivo con puntos o rutas anidadas
Route::get('v1/web/logos/{filename}', function ($filename) {
    $path = 'logos/' . $filename;
    if (!Storage::disk('public')->exists($path)) {
        abort(404, 'Logo no encontrado');
    }
    return response()->file(storage_path('app/public/' . $path));
})->where('filename', '.*');
