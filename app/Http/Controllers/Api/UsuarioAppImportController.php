<?php

namespace App\Http\Controllers\Api;

use App\Exports\UsuariosAppErroresExport;
use App\Exports\UsuariosAppTemplateExport;
use App\Http\Controllers\Controller;
use App\Jobs\ImportarUsuariosAppJob;
use App\Models\ImportacionLog;
use App\Models\UsuarioApp;
use App\Models\UsuarioWeb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class UsuarioAppImportController extends Controller
{
    public function __construct()
    {
    }

    private function esAdministrador($user): bool
    {
        return $user && in_array($user->rol ?? null, [
            UsuarioWeb::ROL_SUPER_ADMIN,
            UsuarioWeb::ROL_ADMINISTRADOR,
        ], true);
    }

    /**
     * ✅ NUEVO: Estadísticas para la vista de importaciones
     * GET /api/usuarios-app/import/stats
     */
    public function stats(Request $request)
    {
        $user = $request->user();

        if (!$this->esAdministrador($user)) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado',
            ], 403);
        }

        try {
            $totalUsuarios = UsuarioApp::count();

            $ultimaImportacion = ImportacionLog::tipo(ImportacionLog::TIPO_USUARIOS_APP)
                ->recientesCompletadas(1)
                ->first();

            $ultimaImportData = null;

            if ($ultimaImportacion) {
                $errores = (int) $ultimaImportacion->errores_count;

                $totalImport = (int) $ultimaImportacion->total;
                if ($totalImport <= 0) {
                    $procesados = (int) $ultimaImportacion->procesados;
                    $exitosos = (int) $ultimaImportacion->exitosos;
                    $totalImport = $procesados > 0
                        ? $procesados
                        : ($exitosos + $errores);
                }

                // Parse fecha en fecha y hora separadas
                $fechaCompleta = $ultimaImportacion->completado_en;
                $fecha = $fechaCompleta ? $fechaCompleta->format('Y-m-d') : null;
                $hora = $fechaCompleta ? $fechaCompleta->format('H:i:s') : null;

                $ultimaImportData = [
                    'id' => (int) $ultimaImportacion->id,  // ✅ Changed from 'import_id'
                    'estado' => $ultimaImportacion->estado,  // ✅ Added
                    'total' => $totalImport,
                    'exitosos' => (int) $ultimaImportacion->exitosos,

                    // 🔹 ambos para evitar errores en frontend
                    'errores' => $errores,
                    'errores_count' => $errores,

                    'fecha' => $fecha,  // ✅ Changed to formatted date
                    'hora' => $hora,    // ✅ Added
                ];
            }

            $ultimas = ImportacionLog::tipo(ImportacionLog::TIPO_USUARIOS_APP)
                ->recientesCompletadas(10)
                ->get();

            $tasaPromedio = $ultimas->isNotEmpty()
                ? round($ultimas->avg('tasa_exito'), 2)
                : 0.0;

            $erroresAcumulados = $ultimas->sum('errores_count');

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $totalUsuarios,
                    'ultima_importacion' => $ultimaImportData,
                    'tasa_exito_promedio' => $tasaPromedio,
                    'errores_pendientes' => $erroresAcumulados,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('Error al obtener stats de usuarios app', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas',
            ], 500);
        }
    }


    /**
     * Importar usuarios app desde archivo Excel/CSV
     * POST /api/usuarios-app/import
     */
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

            // ✅ CORREGIDO: Usar TIPO_USUARIOS_APP (no 'docentes')
            $importLog = ImportacionLog::create([
                'usuario_id' => $user->id,
                'tipo' => ImportacionLog::TIPO_USUARIOS_APP,
                'archivo_original' => $archivoNombre,
                'archivo_temp' => $archivoPath,
                'estado' => ImportacionLog::ESTADO_PENDING,
            ]);

            ImportarUsuariosAppJob::dispatch($importLog->id, $archivoPath);

            Log::info('Importación de usuarios app encolada', [
                'import_id' => $importLog->id,
                'usuario_id' => $user->id,
                'archivo' => $archivoNombre,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'import_id' => $importLog->id,
                    'estado' => $importLog->estado,
                    'mensaje' => 'La importación fue encolada y se procesará en segundo plano',
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
            Log::error('Error al iniciar importación de usuarios app', [
                'usuario_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al iniciar la importación',
            ], 500);
        }
    }

    /**
     * Consultar estado de una importación
     * GET /api/usuarios-app/import/{id}/estado
     */
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
                'tasa_exito' => $importLog->tasa_exito,
                'iniciado_en' => $importLog->iniciado_en?->toIso8601String(),
                'completado_en' => $importLog->completado_en?->toIso8601String(),
                'duracion' => $importLog->duracion_formateada,
                // 'errores' => $importLog->errores_detalle ?? [], // 🚫 OPTIMIZACIÓN: No enviar detalles en polling
            ],
        ]);
    }

    /**
     * Descargar Excel de errores
     * GET /api/usuarios-app/import/{id}/errores
     */
    public function erroresExcel($id)
    {
        $importLog = ImportacionLog::find($id);

        if (!$importLog) {
            return response()->json([
                'success' => false,
                'message' => 'Importación no encontrada',
            ], 404);
        }

        // ✅ CORREGIDO: Usar TIPO_USUARIOS_APP (no 'docentes')
        if ($importLog->tipo !== ImportacionLog::TIPO_USUARIOS_APP) {
            return response()->json([
                'success' => false,
                'message' => 'La importación no corresponde a usuarios app',
            ], 400);
        }

        if (empty($importLog->errores_detalle)) {
            return response()->json([
                'success' => false,
                'message' => 'No existen errores para exportar',
            ], 400);
        }

        $nombreArchivo = 'usuarios_app_errores_' . $importLog->id . '_' .
            now()->format('YmdHis') . '.xlsx';

        return Excel::download(
            new UsuariosAppErroresExport($importLog->errores_detalle),
            $nombreArchivo
        );
    }

    /**
     * Descargar plantilla de usuarios app
     * GET /api/usuarios-app/import/plantilla
     */
    public function downloadTemplate(Request $request)
    {
        if (!$this->esAdministrador($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para descargar la plantilla',
            ], 403);
        }

        return Excel::download(
            new UsuariosAppTemplateExport(),
            'plantilla_usuarios_app_' . now()->format('Ymd') . '.xlsx'
        );
    }
}
