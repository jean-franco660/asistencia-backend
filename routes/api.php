<?php

use App\Http\Controllers\Api\SupervisorDashboardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UsuarioWebController;
use App\Http\Controllers\Api\UsuarioAppController;
use App\Http\Controllers\Api\InstitucionController;
use App\Http\Controllers\Api\AsistenciaController;
use App\Http\Controllers\Api\UsuarioAppImportController;
use App\Http\Controllers\Api\HorariosInstitucionController;
use App\Http\Controllers\Api\FeriadoController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\AppInstitucionController;
use App\Http\Controllers\Api\JustificacionController;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| RUTA DE ESTADO (PRUEBA)
|--------------------------------------------------------------------------
*/
Route::get('/status', function () {
    return response()->json([
        'ok' => true,
        'message' => 'API funcionando correctamente'
    ]);
});

/*
|--------------------------------------------------------------------------
| RUTAS PARA LA APP MÓVIL (DOCENTES)
|--------------------------------------------------------------------------
*/
Route::prefix('v1/app')->group(function () {
    // Login con código modular - THROTTLE: 5 intentos por minuto
    Route::post('/login', [UsuarioAppController::class, 'login'])->middleware('throttle:login');

    // Rutas protegidas con token para la app - THROTTLE: 60 peticiones por minuto
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        // Perfil del docente autenticado
        Route::get('/perfil', [UsuarioAppController::class, 'perfil']);

        // Logout
        Route::post('/logout', [UsuarioAppController::class, 'logout']);

        // Instituciones
        Route::get('/instituciones', [AppInstitucionController::class, 'index']);

        // Asistencias
        Route::post('/asistencia', [AsistenciaController::class, 'store']);
        Route::get('/asistencia/{usuarioId}', [AsistenciaController::class, 'historial']);
        Route::post('/asistencias/sincronizar', [AsistenciaController::class, 'syncMovil']);
        Route::get('/estado-dia', [AsistenciaController::class, 'estadoDia']);

        // Horarios
        Route::get('/horarios-institucion', [HorariosInstitucionController::class, 'index']);

        // Justificaciones (Docente solo puede crear, ver y eliminar las suyas)
        Route::get('/justificaciones', [JustificacionController::class, 'index']);
        Route::post('/justificaciones', [JustificacionController::class, 'store']);
        Route::get('/justificaciones/{id}', [JustificacionController::class, 'show']);
        Route::delete('/justificaciones/{id}', [JustificacionController::class, 'destroy']);
    });
});

/*
|--------------------------------------------------------------------------
| RUTAS PARA LA WEB (ADMINISTRADOR / SUPERVISOR)
|--------------------------------------------------------------------------
*/

Route::prefix('v1/web')->group(function () {
    // Login web - THROTTLE: 5 intentos por minuto
    Route::post('/login', [UsuarioWebController::class, 'login'])->middleware('throttle:login');
});

Route::prefix('v1/web')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // Dashboard
    Route::get('/supervisor/dashboard', [SupervisorDashboardController::class, 'index']);

    // Perfil
    Route::get('/me', [UsuarioWebController::class, 'me']);

    // Logout
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

        // ACCIONES CRÍTICAS - THROTTLE: 30 por minuto
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
        Route::get('/search', [\App\Http\Controllers\Api\Web\ProvisioningController::class, 'search']);
        Route::get('/usuario-app/{usuarioApp}', [\App\Http\Controllers\Api\Web\ProvisioningController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Api\Web\ProvisioningController::class, 'store']);
    });


    /*
    |--------------------------------------------------------------------------
    | USUARIOS APP (Docentes)
    |--------------------------------------------------------------------------
    */
    Route::prefix('usuarios-app')->group(function () {
        // Plantilla de importación
        Route::get('/template', [UsuarioAppImportController::class, 'downloadTemplate']);

        // Rutas específicas ANTES de las rutas con parámetros
        Route::delete('/delete-multiple', [UsuarioAppController::class, 'destroyMultiple']);

        Route::get('/', [UsuarioAppController::class, 'index']);
        Route::post('/', [UsuarioAppController::class, 'store']);
        Route::get('/{id}', [UsuarioAppController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [UsuarioAppController::class, 'update'])->whereNumber('id');
        Route::patch('/{id}', [UsuarioAppController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [UsuarioAppController::class, 'destroy'])->whereNumber('id');
        Route::patch('/{id}/asignar-horario', [UsuarioAppController::class, 'asignarHorario'])->whereNumber('id');
        Route::patch('/{id}/estado', [UsuarioAppController::class, 'cambiarEstado'])->whereNumber('id');

        // IMPORTACIÓN - THROTTLE: 3 por minuto
        Route::middleware('throttle:importaciones')->group(function () {
            Route::get('/import/stats', [UsuarioAppImportController::class, 'stats']); // ⭐ NUEVO
            Route::post('/importar', [UsuarioAppImportController::class, 'import']);
            Route::get('/importacion/{id}', [UsuarioAppImportController::class, 'estadoImportacion'])->whereNumber('id');
            Route::get('/importacion/{id}/errores.xlsx', [UsuarioAppImportController::class, 'erroresExcel'])->whereNumber('id');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | ASIGNACIONES (Usuario App - Institución)
    |--------------------------------------------------------------------------
    */
    Route::post('/usuario-app-institucion/{id}/inactivar', [\App\Http\Controllers\Api\UsuarioAppInstitucionController::class, 'inactivar'])->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | INSTITUCIONES
    |--------------------------------------------------------------------------
    */
    Route::prefix('instituciones')->group(function () {
        // Plantilla de importación
        Route::get('/template', [\App\Http\Controllers\Api\InstitucionImportController::class, 'downloadTemplate']);

        // Rutas específicas ANTES de las rutas con parámetros
        Route::get('/mias', [InstitucionController::class, 'misInstituciones']);
        Route::delete('/delete-multiple', [InstitucionController::class, 'destroyMultiple']);

        // IMPORTACIÓN - THROTTLE: 3 por minuto
        Route::middleware('throttle:importaciones')->group(function () {
            Route::get('/import/stats', [\App\Http\Controllers\Api\InstitucionImportController::class, 'stats']); // ⭐ NUEVO
            Route::post('/importar', [\App\Http\Controllers\Api\InstitucionImportController::class, 'import']);
            Route::get('/importacion/{id}', [\App\Http\Controllers\Api\InstitucionImportController::class, 'estadoImportacion'])->whereNumber('id');
            Route::get('/importacion/{id}/errores.xlsx', [\App\Http\Controllers\Api\InstitucionImportController::class, 'erroresExcel'])->whereNumber('id');
        });

        // CRUD básico
        Route::get('/', [InstitucionController::class, 'index']);
        Route::post('/', [InstitucionController::class, 'store']);
        Route::get('/{id}', [InstitucionController::class, 'show']);
        Route::put('/{id}', [InstitucionController::class, 'update']);
        Route::patch('/{id}', [InstitucionController::class, 'update']);
        Route::delete('/{id}', [InstitucionController::class, 'destroy']);
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
    | ASISTENCIAS
    |--------------------------------------------------------------------------
    */
    Route::prefix('asistencias')->group(function () {
        // Rutas específicas primero
        Route::get('/cabeceras', [AsistenciaController::class, 'listCabeceras']); // ⭐ NUEVO - Fase 5
        Route::get('/semana', [AsistenciaController::class, 'resumenSemanal']);
        Route::get('/mes-grafico', [AsistenciaController::class, 'resumenMensualGrafico']);
        Route::get('/exportar', [AsistenciaController::class, 'exportar'])->name('asistencias.exportar');
        Route::get('/exportar-institucion/{id}', [AsistenciaController::class, 'exportarInstitucion'])->whereNumber('id');

        // ⭐ NUEVO - Obtener marcación individual por ID
        Route::get('/marcaciones/{id}', [AsistenciaController::class, 'getMarcacion'])->whereNumber('id');

        // CRUD básico (marcaciones individuales)
        Route::get('/', [AsistenciaController::class, 'index']);
        Route::get('/{id}', [AsistenciaController::class, 'show'])->whereNumber('id');

        // Revisión de marcaciones (ID es AsistenciaDiaria)
        Route::put('/marcaciones/{id}/review', [AsistenciaController::class, 'updateReview'])->whereNumber('id');
    });

    // Foto de asistencia (fuera del prefix para mantener la ruta original)
    Route::get('/asistencia/foto/{id}', [AsistenciaController::class, 'foto'])->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | JUSTIFICACIONES
    |--------------------------------------------------------------------------
    */
    Route::prefix('justificaciones')->group(function () {
        Route::get('/', [JustificacionController::class, 'index']);
        Route::get('/{id}', [JustificacionController::class, 'show']);
        Route::delete('/{id}', [JustificacionController::class, 'destroy']);

        // ACCIONES CRÍTICAS - THROTTLE: 30 por minuto
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
        Route::get('/', [\App\Http\Controllers\Api\AuditLogController::class, 'index']);
        Route::get('/stats', [\App\Http\Controllers\Api\AuditLogController::class, 'stats']);
        Route::get('/modelo/{modelo}/{id}', [\App\Http\Controllers\Api\AuditLogController::class, 'historialModelo']);
        Route::get('/{id}', [\App\Http\Controllers\Api\AuditLogController::class, 'show']);
    });
});

/*
|--------------------------------------------------------------------------
| RUTA PÚBLICA PARA SERVIR LOGOS
|--------------------------------------------------------------------------
*/
Route::get('v1/web/logos/{filename}', function ($filename) {
    $path = 'logos/' . $filename;

    if (!Storage::disk('public')->exists($path)) {
        abort(404, 'Logo no encontrado');
    }

    return response()->file(storage_path('app/public/' . $path));
})->where('filename', '.*');