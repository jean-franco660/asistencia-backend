<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ImportarInstitucionesJob;
use App\Models\ImportacionLog;
use App\Models\Institucion;
use App\Models\UsuarioWeb;
use App\Exports\InstitucionesErroresExport;
use App\Exports\InstitucionesTemplateExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class InstitucionImportController extends Controller
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
     * Estadísticas para la vista de importaciones
     * GET /api/instituciones/import/stats
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
            $total = Institucion::count();

            $ultimaImportacion = ImportacionLog::tipo(ImportacionLog::TIPO_INSTITUCIONES)
                ->completadas()
                ->latest('completado_en')
                ->first();

            $ultimaImportData = null;

            if ($ultimaImportacion) {
                $completadoEn = $ultimaImportacion->completado_en;

                $ultimaImportData = [
                    'id' => $ultimaImportacion->id,
                    'total' => (int) $ultimaImportacion->total,
                    'exitosos' => (int) $ultimaImportacion->exitosos,
                    'errores_count' => (int) $ultimaImportacion->errores_count,
                    'estado' => $ultimaImportacion->estado,
                    'fecha' => $completadoEn ? $completadoEn->format('d/m/Y') : null,
                    'hora' => $completadoEn ? $completadoEn->format('H:i') : null,
                ];
            }

            $ultimasImportaciones = ImportacionLog::tipo(ImportacionLog::TIPO_INSTITUCIONES)
                ->completadas()
                ->recientes(10)
                ->get();

            $tasaExitoPromedio = $ultimasImportaciones->isNotEmpty()
                ? round((float) $ultimasImportaciones->avg('tasa_exito'), 2)
                : 0.0;

            $erroresPendientes = (int) ImportacionLog::tipo(ImportacionLog::TIPO_INSTITUCIONES)
                ->completadas()
                ->where('errores_count', '>', 0)
                ->sum('errores_count');

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => (int) $total,
                    'ultima_importacion' => $ultimaImportData,
                    'tasa_exito_promedio' => $tasaExitoPromedio,
                    'errores_pendientes' => $erroresPendientes,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('Error al obtener stats de instituciones', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas',
            ], 500);
        }
    }

    /**
     * Importar instituciones desde archivo Excel/CSV
     * POST /api/instituciones/import
     */
    public function import(Request $request)
    {
        $user = $request->user();

        if (!$this->esAdministrador($user)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para importar instituciones',
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
                'tipo' => ImportacionLog::TIPO_INSTITUCIONES,
                'archivo_original' => $archivoNombre,
                'archivo_temp' => $archivoPath,
                'estado' => ImportacionLog::ESTADO_PENDING,
            ]);

            ImportarInstitucionesJob::dispatch($importLog->id, $archivoPath);

            Log::info('Importación de instituciones encolada', [
                'import_id' => $importLog->id,
                'usuario_id' => $user->id,
                'archivo' => $archivoNombre,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'import_id' => (int) $importLog->id,
                    'estado' => (string) $importLog->estado,
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
            Log::error('Error al iniciar importación de instituciones', [
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
     * GET /api/instituciones/import/{id}/estado
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

        // ✅ Asegurar tipo correcto (evita mezclar importaciones)
        if ($importLog->tipo !== ImportacionLog::TIPO_INSTITUCIONES) {
            return response()->json([
                'success' => false,
                'message' => 'La importación no corresponde a instituciones',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'import_id' => (int) $importLog->id,
                'tipo' => (string) $importLog->tipo,
                'estado' => (string) $importLog->estado,
                'total' => (int) $importLog->total,
                'procesados' => (int) $importLog->procesados,
                'exitosos' => (int) $importLog->exitosos,
                'errores_count' => (int) $importLog->errores_count,
                'porcentaje' => (int) $importLog->porcentaje,
                'tasa_exito' => (float) $importLog->tasa_exito,
                'iniciado_en' => $importLog->iniciado_en?->toIso8601String(),
                'completado_en' => $importLog->completado_en?->toIso8601String(),
                'duracion' => $importLog->duracion_formateada,
                // 'errores' => $importLog->errores_detalle ?? [], // 🚫 OPTIMIZACIÓN: No enviar detalles en polling
            ],
        ]);
    }

    /**
     * Descargar Excel de errores
     * GET /api/instituciones/import/{id}/errores
     */
    public function erroresExcel(Request $request, int $id)
    {
        $user = $request->user();

        // ✅ Seguridad: solo admin
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

        if ($importLog->tipo !== ImportacionLog::TIPO_INSTITUCIONES) {
            return response()->json([
                'success' => false,
                'message' => 'La importación no corresponde a instituciones',
            ], 400);
        }

        if (empty($importLog->errores_detalle)) {
            return response()->json([
                'success' => false,
                'message' => 'No existen errores para exportar',
            ], 400);
        }

        $nombreArchivo = 'instituciones_errores_' . $importLog->id . '_' . now()->format('YmdHis') . '.xlsx';

        return Excel::download(
            new InstitucionesErroresExport($importLog->errores_detalle),
            $nombreArchivo
        );
    }

    /**
     * Descargar plantilla de instituciones
     * GET /api/instituciones/import/plantilla
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
            new InstitucionesTemplateExport(),
            'plantilla_instituciones_' . now()->format('Ymd') . '.xlsx'
        );
    }
}
