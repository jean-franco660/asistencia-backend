<?php

namespace App\Http\Controllers\Api\Web;

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

/**
 * Gestiona la importación masiva de instituciones desde archivos Excel o CSV.
 *
 * Solo accesible para administradores y super_admin. El proceso de importación
 * es asíncrono: el archivo se almacena temporalmente y se despacha un Job que
 * procesa las filas en segundo plano. El estado del proceso se consulta mediante
 * el método estadoImportacion. Los errores se pueden descargar como Excel.
 */
class InstitucionImportController extends Controller
{
    public function __construct()
    {
    }



    /**
     * Retorna las estadísticas generales de importación de instituciones.
     *
     * Incluye el total de instituciones registradas, datos de la última importación
     * (en progreso o completada), la tasa de éxito promedio de las últimas 10
     * importaciones y el total acumulado de errores pendientes.
     * Solo accesible para administradores.
     */
    public function stats(Request $request)
    {
        $user = $request->user();

        if (!($user instanceof \App\Models\UsuarioWeb && $user->esAdminOSuperAdmin())) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado',
            ], 403);
        }

        try {
            $total = Institucion::count();

            $ultimaImportacion = ImportacionLog::tipo(ImportacionLog::TIPO_INSTITUCIONES)
                ->enProgreso()
                ->latest('created_at')
                ->first()
                ?? ImportacionLog::tipo(ImportacionLog::TIPO_INSTITUCIONES)
                    ->latest('created_at')
                    ->first();

            $ultimaImportData = null;

            if ($ultimaImportacion) {
                $fechaReferencia = $ultimaImportacion->completado_en ?? $ultimaImportacion->iniciado_en;

                $ultimaImportData = [
                    'id' => $ultimaImportacion->id,
                    'total' => (int) $ultimaImportacion->total,
                    'exitosos' => (int) $ultimaImportacion->exitosos,
                    'errores_count' => (int) $ultimaImportacion->errores_count,
                    'estado' => $ultimaImportacion->estado,
                    'fecha' => $fechaReferencia ? $fechaReferencia->format('d/m/Y') : null,
                    'hora' => $fechaReferencia ? $fechaReferencia->format('H:i') : null,
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
     * Inicia la importación de instituciones desde un archivo Excel o CSV.
     *
     * Valida que el archivo sea válido (xlsx, xls, csv, máx 10 MB), lo almacena
     * temporalmente, crea un registro de log y despacha el Job de importación
     * en segundo plano. La respuesta incluye el import_id para consultar el estado
     * mediante el método estadoImportacion. Solo accesible para administradores.
     */
    public function import(Request $request)
    {
        $user = $request->user();

        Log::info('Iniciando importación de instituciones', [
            'usuario_id' => $user->id ?? null,
            'has_file' => $request->hasFile('archivo'),
            'files' => $request->allFiles(),
        ]);

        if (!($user instanceof \App\Models\UsuarioWeb && $user->esAdminOSuperAdmin())) {
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
            Log::info('Validando archivo', [
                'usuario_id' => $user->id,
                'file_info' => $request->file('archivo') ? [
                    'name' => $request->file('archivo')->getClientOriginalName(),
                    'size' => $request->file('archivo')->getSize(),
                    'mime' => $request->file('archivo')->getMimeType(),
                ] : null,
            ]);

            $request->validate([
                'archivo' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            ]);

            Log::info('Archivo validado, guardando', [
                'usuario_id' => $user->id,
            ]);

            $archivo = $request->file('archivo');
            $archivoPath = $archivo->store('temp/importaciones');
            $archivoNombre = $archivo->getClientOriginalName();

            Log::info('Archivo guardado, creando log', [
                'usuario_id' => $user->id,
                'path' => $archivoPath,
            ]);

            $importLog = ImportacionLog::create([
                'usuario_id' => $user->id,
                'tipo' => ImportacionLog::TIPO_INSTITUCIONES,
                'archivo_original' => $archivoNombre,
                'archivo_temp' => $archivoPath,
                'estado' => ImportacionLog::ESTADO_PENDING,
            ]);

            Log::info('Log creado, despachando job', [
                'import_id' => $importLog->id,
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
     * Consulta el estado de progreso de una importación específica.
     *
     * Los detalles de errores no se incluyen en esta respuesta para evitar
     * payloads excesivos durante el polling. Se valida que el log corresponda
     * al tipo TIPO_INSTITUCIONES para evitar cruce con importaciones de otro tipo.
     */
    public function estadoImportacion(Request $request, int $id)
    {
        $user = $request->user();

        if (!($user instanceof \App\Models\UsuarioWeb && $user->esAdminOSuperAdmin())) {
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

        // Asegurar tipo correcto (evita mezclar importaciones)
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
                // 'errores' => $importLog->errores_detalle ?? [], // OPTIMIZACIÓN: No enviar detalles en polling
            ],
        ]);
    }

    /**
     * Descarga un archivo Excel con el detalle de los errores de una importación.
     *
     * Retorna 400 si no existen errores. Solo disponible para importaciones de tipo
     * TIPO_INSTITUCIONES. Solo accesible para administradores.
     */
    public function erroresExcel(Request $request, int $id)
    {
        $user = $request->user();

        if (!($user instanceof \App\Models\UsuarioWeb && $user->esAdminOSuperAdmin())) {
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
     * Descarga la plantilla Excel para la importación de instituciones.
     *
     * La plantilla incluye los campos requeridos y de ejemplo para facilitar
     * la preparación del archivo de importación. Solo accesible para administradores.
     */
    public function downloadTemplate(Request $request)
    {
        if (!($request->user() instanceof \App\Models\UsuarioWeb && $request->user()->esAdminOSuperAdmin())) {
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
