<?php

namespace App\Http\Controllers\Api;

use App\Exports\UsuariosAppErroresExport;
use App\Http\Controllers\Controller;
use App\Jobs\ImportarUsuariosAppJob;
use App\Models\AuditLog;
use App\Models\ImportacionLog;
use App\Models\UsuarioApp;
use App\Models\UsuarioWeb;
use App\Services\ImportUsuariosAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class DocenteImportController extends Controller
{
    public function __construct(
        protected ImportUsuariosAppService $importService
    ) {}

    /**
     * ✅ CORREGIDO: Usar ROL_ADMINISTRADOR
     */
    private function esAdministrador($user): bool
    {
        return $user && in_array($user->rol ?? null, [
            UsuarioWeb::ROL_SUPER_ADMIN,
            UsuarioWeb::ROL_ADMINISTRADOR,  // ✅ CORRECTO
        ], true);
    }

    public function import(Request $request)
    {
        $user = $request->user();

        if (!$this->esAdministrador($user)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para importar usuarios',
            ], 403);
        }

        if (!$request->hasFile('archivo')) {
            return response()->json([
                'success' => false,
                'message' => 'No se envió ningún archivo (campo: archivo)',
            ], 400);
        }

        try {
            $request->validate([
                'archivo' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            ]);

            $archivo = $request->file('archivo');
            $archivoPath = $archivo->store('temp/importaciones');
            $archivoNombre = $archivo->getClientOriginalName();

            $importLog = ImportacionLog::create([
                'usuario_id' => $user->id,
                'tipo' => 'docentes',
                'archivo_original' => $archivoNombre,
                'archivo_temp' => $archivoPath,
                'estado' => 'pending',
                'total' => 0,
                'procesados' => 0,
                'exitosos' => 0,
                'errores_count' => 0,
                'errores_detalle' => [],
            ]);

            ImportarUsuariosAppJob::dispatch($importLog->id, $archivoPath);

            Log::info('Importación de docentes encolada', [
                'import_id' => $importLog->id,
                'usuario_id' => $user->id,
                'archivo' => $archivoNombre,
            ]);

            // ✅ SIMPLIFICADO: El trait Auditable del ImportacionLog ya registrará 'created'
            // Si necesitas metadata adicional, usa:
            AuditLog::create([
                'actor_id' => $user->id,
                'actor_type' => get_class($user),
                'actor_nombre' => $user->nombre,
                'actor_rol' => $user->rol,
                'accion' => 'importacion_iniciada',
                'descripcion' => 'Importación masiva de docentes iniciada',
                'modelo' => UsuarioApp::class,
                'modelo_id' => null,
                'modelo_nombre' => 'Importación masiva de docentes',
                'metadata' => [
                    'import_id' => $importLog->id,
                    'archivo_nombre' => $archivoNombre,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'url' => $request->fullUrl(),
                'metodo_http' => $request->method(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'import_id' => $importLog->id,
                    'estado' => $importLog->estado,
                    'mensaje' => 'La importación fue encolada y se procesará con el worker',
                ],
                'message' => 'Importación iniciada correctamente',
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            Log::error('Error al iniciar importación de docentes', [
                'usuario_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al iniciar la importación',
            ], 500);
        }
    }

    public function estadoImportacion(Request $request, int $id)
    {
        $user = $request->user();

        if (!$this->esAdministrador($user)) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado',
            ], 403);
        }

        $importLog = ImportacionLog::find($id);

        if (!$importLog) {
            return response()->json([
                'success' => false,
                'message' => 'Importación no encontrada',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'import_id' => $importLog->id,
                'tipo' => $importLog->tipo,
                'estado' => $importLog->estado,
                'total' => (int) $importLog->total,
                'procesados' => (int) $importLog->procesados,
                'exitosos' => (int) $importLog->exitosos,
                'errores_count' => (int) $importLog->errores_count,
                'porcentaje' => $importLog->porcentaje,
                'iniciado_en' => $importLog->iniciado_en?->toISOString(),
                'completado_en' => $importLog->completado_en?->toISOString(),
                'errores' => $importLog->errores_detalle ?? [],
            ],
        ]);
    }

    public function erroresExcel($id)
    {
        $importLog = ImportacionLog::find($id);

        if (!$importLog) {
            return response()->json([
                'success' => false,
                'message' => 'Importación no encontrada',
            ], 404);
        }

        if ($importLog->tipo !== 'docentes') {
            return response()->json([
                'success' => false,
                'message' => 'La importación no corresponde a docentes',
            ], 400);
        }

        if (empty($importLog->errores_detalle)) {
            return response()->json([
                'success' => false,
                'message' => 'No existen errores para exportar',
            ], 400);
        }

        $nombreArchivo = 'docentes_errores_' . $importLog->id . '.xlsx';

        return Excel::download(
            new UsuariosAppErroresExport($importLog->errores_detalle),
            $nombreArchivo
        );
    }

    public function downloadTemplate(Request $request)
    {
        if (!$this->esAdministrador($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para descargar la plantilla',
            ], 403);
        }

        return Excel::download(
            new \App\Exports\UsuariosAppTemplateExport(),
            'plantilla_usuarios_app.xlsx'
        );
    }
}