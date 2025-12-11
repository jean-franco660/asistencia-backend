<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UsuarioWebController;
use App\Http\Controllers\Api\UsuarioAppController;
use App\Http\Controllers\Api\InstitucionController;
use App\Http\Controllers\Api\AsistenciaController;
use App\Http\Controllers\ImportDocentesController;
use App\Http\Controllers\Api\InstitucionFeriadoController;
use App\Http\Controllers\Api\HorariosInstitucionController;
use App\Http\Controllers\Api\FeriadoController;
use App\Http\Controllers\Api\DirectorDashboardController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\AppInstitucionController;

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
    Route::post('/login', [UsuarioAppController::class, 'login']);

    // Rutas protegidas con token para la app
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/instituciones', [AppInstitucionController::class, 'index']);
        Route::post('/asistencia', [AsistenciaController::class, 'store']);
        Route::get('/asistencia/{usuarioId}', [AsistenciaController::class, 'historial']);
        Route::post('/asistencias/sincronizar', [AsistenciaController::class, 'syncMovil']);
        Route::get('/estado-dia', [AsistenciaController::class, 'estadoDia']);
        Route::get('/horarios-institucion', [HorariosInstitucionController::class, 'index']);

    });
});

/*
|--------------------------------------------------------------------------
| RUTAS PARA LA WEB (ADMIN / DIRECTOR)
|--------------------------------------------------------------------------
*/

Route::prefix('v1/web')->group(function () {
    Route::post('/login', [UsuarioWebController::class, 'login']);
});

Route::prefix('v1/web')->middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Gestión de usuarios web (Admin / Directores)
    |--------------------------------------------------------------------------
    */
    Route::get('/director/dashboard', [DirectorDashboardController::class, 'index']);
    Route::get('/usuarios-web', [UsuarioWebController::class, 'index']);
    Route::get('/usuarios-web/pendientes', [UsuarioWebController::class, 'pendientes']);
    Route::post('/usuarios-web', [UsuarioWebController::class, 'store']);
    Route::get('/usuarios-web/{id}', [UsuarioWebController::class, 'show']);
    Route::put('/usuarios-web/{id}', [UsuarioWebController::class, 'update']);
    Route::delete('/usuarios-web/{id}', [UsuarioWebController::class, 'destroy']);

    // Autorización y rechazo de usuarios
    Route::post('/usuarios-web/autorizar/{id}', [UsuarioWebController::class, 'autorizar']);
    Route::post('/usuarios-web/rechazar/{id}', [UsuarioWebController::class, 'rechazar']);
    Route::post('/usuarios-web/importar', [ImportDocentesController::class, 'importar']);

    /*
    |--------------------------------------------------------------------------
    | Gestión de docentes (usuarios_app)
    |--------------------------------------------------------------------------
    */
    Route::get('/usuarios-app', [UsuarioAppController::class, 'index']);
    Route::post('/usuarios-app', [UsuarioAppController::class, 'store']);
    Route::get('/usuarios-app/{id}', [UsuarioAppController::class, 'show']);
    Route::put('/usuarios-app/{id}', [UsuarioAppController::class, 'update']);
    Route::delete('/usuarios-app/{id}', [UsuarioAppController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Gestión de instituciones
    |--------------------------------------------------------------------------
    */
    Route::get('/instituciones', [InstitucionController::class, 'index']);
    Route::get('/instituciones/mias', [InstitucionController::class, 'misInstituciones']);
    Route::post('/instituciones', [InstitucionController::class, 'store']);
    Route::get('/instituciones/{id}', [InstitucionController::class, 'show']);
    Route::post('/instituciones/{id}', [InstitucionController::class, 'update']); // Cambio a POST para soportar archivos
    Route::delete('/instituciones/{id}', [InstitucionController::class, 'destroy']);

    /*--------------------------------------------------------------------------
    | Gestión de horarios de institución
    |---------------------------------------------------------------------------
    */
    Route::get('/horarios', [HorariosInstitucionController::class, 'index']);
    Route::post('/horarios', [HorariosInstitucionController::class, 'store']);
    Route::put('/horarios/{id}', [HorariosInstitucionController::class, 'update']);
    Route::delete('/horarios/{id}', [HorariosInstitucionController::class, 'destroy']);
    

    /*--------------------------------------------------------------------------
    | Feriados Nacionales + Feriados Institucionales
    |--------------------------------------------------------------------------
    */

    Route::get('/feriados', [FeriadoController::class, 'index']);
    Route::post('/feriados', [FeriadoController::class, 'store']);
    Route::put('/feriados/{id}', [FeriadoController::class, 'update']);
    Route::delete('/feriados/{id}', [FeriadoController::class, 'destroy']);
    Route::post('/feriados/actualizar-automatico', [FeriadoController::class, 'actualizarAutomatico']);


    /*--------------------------------------------------------------------------
    | Gestión de asistencias
    |--------------------------------------------------------------------------*/
    Route::post('/asistencias/sincronizar', [AsistenciaController::class, 'sync']);
    Route::get('/asistencias/semana', [AsistenciaController::class, 'resumenSemanal']);
    Route::get('/asistencias/mes-grafico', [AsistenciaController::class, 'resumenMensualGrafico']);
    Route::get('/asistencias/{id}', [AsistenciaController::class, 'show'])
        ->whereNumber('id');
    Route::get('/asistencias', [AsistenciaController::class, 'index']);
    Route::get('/asistencias/{id}', [AsistenciaController::class, 'show']);
    Route::get('/asistencia/foto/{id}', [AsistenciaController::class, 'foto']);

    /*-------------------------------------------------------------------------- 
    | Dashboard Stats (Admin/Director)
    |--------------------------------------------------------------------------*/
    Route::get('/stats', [StatsController::class, 'index']);

    /*
    |--------------------------------------------------------------------------
    | Cierre de sesión
    |--------------------------------------------------------------------------
    */
    Route::post('/logout', function (Request $request) {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada correctamente']);
    });

    Route::get('/me', [UsuarioWebController::class, 'me']);

});