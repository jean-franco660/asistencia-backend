<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exports\AsistenciasMultipleExport;
use App\Models\Asistencia;
use App\Models\UsuarioApp;
use App\Models\Feriado;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AsistenciaController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        try {
            $query = Asistencia::with(['usuario', 'institucion'])
                ->orderBy('fecha_hora', 'desc');

            // Filtros
            if ($request->filled('fecha_inicio')) {
                $query->whereDate('fecha_hora', '>=', $request->fecha_inicio);
            }

            if ($request->filled('fecha_fin')) {
                $query->whereDate('fecha_hora', '<=', $request->fecha_fin);
            }

            if ($request->filled('institucion_id')) {
                $query->where('institucion_id', $request->institucion_id);
            }

            if ($request->filled('tipo')) {
                $query->where('tipo', $request->tipo);
            }

            // Restricción: Director solo ve sus instituciones
            $user = $request->user();
            if ($user->rol === 'director') {
                $institucionesIds = $user->instituciones->pluck('id');
                $query->whereIn('institucion_id', $institucionesIds);
            }

            $asistencias = $query->get();

            return response()->json([
                'success' => true,
                'data' => $asistencias,
                'total' => $asistencias->count()
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'error' => "Error al obtener asistencias",
                'message' => $e->getMessage()
            ], 500);
        }
    }

        public function store(Request $request)
        {
            try {
                $validated = $request->validate([
                    'usuario_id' => 'required|integer|exists:usuarios_app,id',
                    'institucion_id' => 'required|integer|exists:instituciones,id',
                    'fecha_hora' => 'required|date',
                    'latitud' => 'required|numeric',
                    'longitud' => 'required|numeric',
                    'dentro_rango' => 'required|boolean',
                    'tipo' => 'required|in:entrada,salida',
                    'turno' => 'nullable|string',
                    'foto' => 'nullable|string' // base64
                ]);

                $fecha = Carbon::parse($validated['fecha_hora']);

                /*
                |--------------------------------------------------------------------------
                | 1) Validar feriados nacionales e institucionales
                |--------------------------------------------------------------------------
                */
                $fn = Feriado::where('tipo', 'nacional')
                    ->where('dia', $fecha->day)
                    ->where('mes', $fecha->month)
                    ->where('activo', true)
                    ->first();

                if ($fn) {
                    return response()->json([
                        'success' => false,
                        'message' => "No laborable: Feriado Nacional - {$fn->descripcion}"
                    ], 403);
                }

                $fi = Feriado::where('tipo', 'institucional')
                    ->where('institucion_id', $validated['institucion_id'])
                    ->where('dia', $fecha->day)
                    ->where('mes', $fecha->month)
                    ->where('activo', true)
                    ->first();

                if ($fi) {
                    return response()->json([
                        'success' => false,
                        'message' => "No laborable: Feriado Institucional - {$fi->descripcion}"
                    ], 403);
                }

                /*
                |--------------------------------------------------------------------------
                | 2) Validar horario de la institución
                |--------------------------------------------------------------------------
                */
                $diaMapa = [
                    'monday' => 'L', 'tuesday' => 'M', 'wednesday' => 'X',
                    'thursday' => 'J', 'friday' => 'V', 'saturday' => 'S', 'sunday' => 'D'
                ];

                $diaHoy = $diaMapa[strtolower($fecha->dayName)];

                $horario = DB::table('horarios_institucion')
                    ->where('institucion_id', $validated['institucion_id'])
                    ->whereJsonContains('dias_semana', $diaHoy)
                    ->where('activo', true)
                    ->first();

                if (!$horario) {
                    return response()->json([
                        'success' => false,
                        'message' => "El día de hoy no es laborable"
                    ], 403);
                }

                /*
                |--------------------------------------------------------------------------
                | 3) Calcular estado (puntual, tarde, salida_antes, etc.)
                |--------------------------------------------------------------------------
                */
                $horaMarcada = $fecha->format('H:i:s');
                $horaEntradaMax = Carbon::parse($horario->hora_entrada)
                    ->addMinutes($horario->tolerancia_minutos)
                    ->format('H:i:s');

                if ($validated['tipo'] === 'entrada') {
                    $estado = ($horaMarcada <= $horaEntradaMax) ? 'a_tiempo' : 'tarde';
                } else {
                    $estado = ($horaMarcada < $horario->hora_salida) ? 'salida_antes' : 'a_tiempo';
                }

                /*
                |--------------------------------------------------------------------------
                | 4) Guardar selfie si existe
                |--------------------------------------------------------------------------
                */
                $fotoPath = null;

                if (!empty($validated['foto'])) {
                    $fotoPath = 'selfies/' . uniqid('selfie_') . '.jpg';
                    Storage::disk('public')->put($fotoPath, base64_decode($validated['foto']));
                }

                /*
                |--------------------------------------------------------------------------
                | 5) Registrar asistencia
                |--------------------------------------------------------------------------
                */
                $asistencia = Asistencia::create([
                    'usuario_id' => $validated['usuario_id'],
                    'institucion_id' => $validated['institucion_id'],
                    'fecha_hora' => $validated['fecha_hora'],
                    'latitud' => $validated['latitud'],
                    'longitud' => $validated['longitud'],
                    'dentro_rango' => $validated['dentro_rango'],
                    'foto' => $fotoPath,
                    'tipo' => $validated['tipo'],
                    'turno' => $validated['turno'] ?? null,
                    'estado' => $estado,
                    'sincronizado' => true
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Asistencia registrada correctamente',
                    'data' => $asistencia
                ], 201);

            } catch (\Exception $e) {
                Log::error("Error en store(): " . $e->getMessage());

                return response()->json([
                    'success' => false,
                    'message' => "Error al registrar asistencia",
                    'error' => $e->getMessage()
                ], 500);
            }
        }


    public function show($id)
    {
        try {
            $asistencia = Asistencia::with(['usuario', 'institucion'])->find($id);

            if (!$asistencia) {
                return response()->json(['message' => 'No encontrado'], 404);
            }

            return response()->json($asistencia);
        } catch (\Exception $e) {
            Log::error("Error en show(): " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function estadoDia(Request $request)
    {
        $user = $request->user();
        $today = now();

        // Feriado nacional
        $fn = Feriado::where('tipo', 'nacional')
                     ->where('dia', $today->day)
                     ->where('mes', $today->month)
                     ->where('activo', true)
                     ->first();

        if ($fn) {
            return response()->json([
                'laborable' => false,
                'motivo' => "Feriado Nacional: {$fn->descripcion}",
                'puede_marcar' => false,
                'horario' => null,
            ]);
        }

        // Feriado institucional
        $instituciones = $user->instituciones->pluck('id');
        $fi = Feriado::where('tipo', 'institucional')
                    ->whereIn('institucion_id', $instituciones)
                    ->where('dia', $today->day)
                    ->where('mes', $today->month)
                    ->where('activo', true)
                    ->first();

        if ($fi) {
            return response()->json([
                'laborable' => false,
                'motivo' => "Feriado Institucional: {$fi->descripcion}",
                'puede_marcar' => false,
                'horario' => null,
            ]);
        }

        // Buscar horario para hoy
        $diaMapa = [
            'monday' => 'L','tuesday' => 'M','wednesday' => 'X',
            'thursday' => 'J','friday' => 'V','saturday' => 'S','sunday' => 'D'
        ];

        $diaHoy = $diaMapa[strtolower($today->dayName)] ?? null;

        $horario = DB::table('horarios_institucion')
            ->whereIn('institucion_id', $instituciones)
            ->whereJsonContains('dias_semana', $diaHoy)
            ->where('activo', true)
            ->first();

        if (!$horario) {
            return response()->json([
                'laborable' => false,
                'motivo' => "Día no laborable",
                'puede_marcar' => false,
                'horario' => null,
            ]);
        }

        return response()->json([
            'laborable' => true,
            'motivo' => null,
            'puede_marcar' => true,
            'horario' => [
                'turno' => $horario->nombre_turno,
                'hora_entrada' => $horario->hora_entrada,
                'hora_salida' => $horario->hora_salida,
                'tolerancia_minutos' => $horario->tolerancia_minutos,
            ]
        ]);
    }

    public function resumenSemanal()
    {
        $user = auth()->user();
        $hoy = Carbon::now();
        $inicioSemana = $hoy->copy()->startOfWeek();
        $finSemana = $hoy->copy()->endOfWeek();

        $asistencias = Asistencia::where('usuario_id', $user->id)
            ->whereBetween('fecha_hora', [$inicioSemana, $finSemana])
            ->orderBy('fecha_hora')
            ->get();

        $dias = collect();
        for ($dia = $inicioSemana->copy(); $dia <= $finSemana; $dia->addDay()) {
            $fecha = $dia->format('Y-m-d');

            $registros = $asistencias->filter(function ($item) use ($fecha) {
                return $item->fecha_hora->format('Y-m-d') === $fecha;
            });

            $entrada = $registros->firstWhere('tipo', 'entrada');
            $salida = $registros->firstWhere('tipo', 'salida');

            $dias->push([
                'fecha' => $fecha,
                'dia' => $dia->isoFormat('dddd'),
                'entrada' => $entrada ? $entrada->fecha_hora->format('H:i:s') : null,
                'salida' => $salida ? $salida->fecha_hora->format('H:i:s') : null,
                'puntual' => $entrada ? $entrada->dentro_rango : null,
                'faltó' => $registros->isEmpty()
            ]);
        }

        return response()->json([
            'usuario' => $user->id,
            'desde' => $inicioSemana->toDateString(),
            'hasta' => $finSemana->toDateString(),
            'resumen' => $dias,
            'totales' => [
                'dias_asistidos' => $dias->where('faltó', false)->count(),
                'faltas' => $dias->where('faltó', true)->count(),
                'puntual' => $dias->where('puntual', true)->count(),
                'impuntual' => $dias->where('puntual', false)->count(),
            ]
        ]);
    }

    public function resumenMensualGrafico()
    {
        $user = auth()->user();
        $hoy = Carbon::now('America/Lima');
        $inicioMes = $hoy->copy()->startOfMonth();
        $finMes = $hoy->copy()->endOfMonth();

        $isAdmin = $user->rol === 'administrador';

        $query = Asistencia::query();

        if (!$isAdmin) {
            $query->where('usuario_id', $user->id);
        }

        $labels = [];
        $asistencias = [];
        $faltas = [];

        // Solo hasta hoy, no todo el mes
        $diasMes = min($hoy->day, $finMes->day);

        for ($dia = 1; $dia <= $diasMes; $dia++) {
            $fecha = $inicioMes->copy()->day($dia);
            
            // Solo procesar días que ya pasaron
            if ($fecha->isFuture()) {
                continue;
            }

            $labels[] = (string)$dia;

            // Verificar si es día laborable
            $diaMapa = [
                'monday' => 'L', 'tuesday' => 'M', 'wednesday' => 'X',
                'thursday' => 'J', 'friday' => 'V', 'saturday' => 'S', 'sunday' => 'D'
            ];
            $diaHoy = $diaMapa[strtolower($fecha->dayName)];

            // Verificar feriados
            $esFeriado = Feriado::where('tipo', 'nacional')
                ->where('dia', $fecha->day)
                ->where('mes', $fecha->month)
                ->where('activo', true)
                ->exists();

            if ($esFeriado) {
                continue; // No incluir días feriados
            }

            // Verificar si hay horario para este día
            $hayHorario = DB::table('horarios_institucion')
                ->whereJsonContains('dias_semana', $diaHoy)
                ->where('activo', true)
                ->exists();

            if (!$hayHorario) {
                continue; // No incluir días sin horario
            }

            // Buscar ENTRADA
            $entrada = (clone $query)
                ->whereDate('fecha_hora', $fecha->toDateString())
                ->where('tipo', 'entrada')
                ->first();

            // Buscar SALIDA
            $salida = (clone $query)
                ->whereDate('fecha_hora', $fecha->toDateString())
                ->where('tipo', 'salida')
                ->first();

            // LÓGICA DE ASISTENCIA/FALTA
            if ($entrada && $salida) {
                $asistencias[] = 1;   // presente
                $faltas[] = 0;
            } elseif ($entrada || $salida) {
                // Tiene entrada o salida pero no ambas
                $asistencias[] = 1;   // cuenta como asistencia parcial
                $faltas[] = 0;
            } else {
                // No tiene ni entrada ni salida en un día laborable que ya pasó
                $asistencias[] = 0;
                $faltas[] = 1;
            }
        }

        return response()->json([
            'labels' => $labels,
            'asistencias' => $asistencias,
            'faltas' => $faltas,
            'periodo' => [
                'mes' => $hoy->isoFormat('MMMM YYYY'),
                'inicio' => $inicioMes->toDateString(),
                'fin' => $finMes->toDateString(),
            ]
        ]);
    }

    public function exportar(Request $request)
    {
        $filters = [
            'fecha_inicio' => $request->fecha_inicio,
            'fecha_fin' => $request->fecha_fin,
            'institucion_id' => $request->institucion_id,
            'tipo' => $request->tipo,
            'user' => $request->user(), // Para filtrar por director
        ];

        $filename = 'Reporte_Asistencias_' . date('Y-m-d_His') . '.xlsx';

        return Excel::download(
            new AsistenciasMultipleExport($filters),
            $filename
        );
    }

    public function syncMovil(Request $request)
    {
        Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        Log::info('🔍 RECIBIENDO SINCRONIZACIÓN DE ASISTENCIAS');
        Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        
        // Log del request completo
        Log::info('📦 Request data:', [
            'content_length' => $request->header('Content-Length'),
            'content_type' => $request->header('Content-Type'),
            'has_asistencias' => $request->has('asistencias'),
            'asistencias_count' => is_array($request->input('asistencias')) ? count($request->input('asistencias')) : 0,
        ]);

        $validated = $request->validate([
            'asistencias' => 'required|array|min:1',
            'asistencias.*.usuario_id' => 'required|integer|exists:usuarios_app,id',
            'asistencias.*.institucion_id' => 'required|integer|exists:instituciones,id',
            'asistencias.*.fecha_hora' => 'required|date',
            'asistencias.*.dentro_rango' => 'required|boolean',
            'asistencias.*.latitud' => 'required|numeric',
            'asistencias.*.longitud' => 'required|numeric',
            'asistencias.*.foto' => 'nullable|string', // ✅ CAMBIO: De required a nullable
            'asistencias.*.tipo' => 'required|in:entrada,salida',
            'asistencias.*.turno' => 'required|string',
            'asistencias.*.falta' => 'required|boolean',
            'asistencias.*.horario_id' => 'nullable|integer', // ✅ NUEVO: Agregar horario_id
        ]);

        $registradas = [];
        $omitidas = [];

        foreach ($validated['asistencias'] as $index => $item) {
            Log::info("🔍 Procesando asistencia #{$index}:", [
                'usuario_id' => $item['usuario_id'],
                'tipo' => $item['tipo'],
                'fecha_hora' => $item['fecha_hora'],
                'tiene_foto' => !empty($item['foto']),
                'foto_length' => !empty($item['foto']) ? strlen($item['foto']) : 0,
                'foto_preview' => !empty($item['foto']) ? substr($item['foto'], 0, 50) . '...' : null,
            ]);

            $fecha = Carbon::parse($item['fecha_hora']);

            // Feriado nacional
            $fn = Feriado::where('tipo', 'nacional')
                        ->where('dia', $fecha->day)
                        ->where('mes', $fecha->month)
                        ->where('activo', true)
                        ->first();
            
            if ($fn) {
                Log::warning("⚠️ Asistencia omitida: Feriado Nacional");
                $omitidas[] = array_merge($item, ['motivo' => "Feriado Nacional: {$fn->descripcion}"]);
                continue;
            }

            // Feriado institucional
            $fi = Feriado::where('tipo', 'institucional')
                        ->where('institucion_id', $item['institucion_id'])
                        ->where('dia', $fecha->day)
                        ->where('mes', $fecha->month)
                        ->where('activo', true)
                        ->first();
            
            if ($fi) {
                Log::warning("⚠️ Asistencia omitida: Feriado Institucional");
                $omitidas[] = array_merge($item, ['motivo' => "Feriado Institucional: {$fi->descripcion}"]);
                continue;
            }

            // Horario válido hoy
            $diaMapa = [
                'monday' => 'L','tuesday' => 'M','wednesday' => 'X',
                'thursday' => 'J','friday' => 'V','saturday' => 'S','sunday' => 'D'
            ];
            $diaHoy = $diaMapa[strtolower($fecha->dayName)];

            $horario = DB::table('horarios_institucion')
                ->where('institucion_id', $item['institucion_id'])
                ->whereJsonContains('dias_semana', $diaHoy)
                ->where('activo', true)
                ->first();

            if (!$horario) {
                Log::warning("⚠️ Asistencia omitida: Día no laborable");
                $omitidas[] = array_merge($item, ['motivo' => "Día no laborable"]);
                continue;
            }

            // Calcular estado
            $horaMarcada = $fecha->format('H:i:s');
            $horaEntradaMax = Carbon::parse($horario->hora_entrada)
                ->addMinutes($horario->tolerancia_minutos)
                ->format('H:i:s');

            if ($item['tipo'] === 'entrada') {
                $estado = ($horaMarcada <= $horaEntradaMax) ? 'a_tiempo' : 'tarde';
            } else {
                $estado = ($horaMarcada < $horario->hora_salida) ? 'salida_antes' : 'a_tiempo';
            }

            // ✅ GUARDAR SELFIE SI EXISTE
            $fotoPath = null;
            if (!empty($item['foto'])) {
                try {
                    Log::info("📸 Procesando foto Base64...");
                    $fotoData = base64_decode($item['foto']);
                    
                    if ($fotoData === false) {
                        Log::error("❌ Error decodificando Base64");
                    } else {
                        $fileName = 'selfies/' . uniqid('selfie_') . '.jpg';
                        
                        // Guardar en S3 (storage/public)
                        Storage::disk('public')->put($fileName, $fotoData);
                        $fotoPath = $fileName;
                        
                        Log::info("✅ Foto guardada exitosamente:", [
                            'path' => $fotoPath,
                            'size' => strlen($fotoData) . ' bytes'
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error("❌ Error guardando foto: " . $e->getMessage());
                }
            } else {
                Log::info("⚠️ No hay foto para guardar");
            }

            // Registrar asistencia
            $registro = Asistencia::create([
                'usuario_id' => $item['usuario_id'],
                'institucion_id' => $item['institucion_id'],
                'fecha_hora' => $item['fecha_hora'],
                'dentro_rango' => $item['dentro_rango'],
                'latitud' => $item['latitud'],
                'longitud' => $item['longitud'],
                'foto' => $fotoPath, // ✅ Ruta de S3 o null
                'tipo' => $item['tipo'],
                'turno' => $item['turno'],
                'falta' => $item['falta'],
                'sincronizado' => true,
                'estado' => $estado,
            ]);

            Log::info("✅ Asistencia registrada:", [
                'id' => $registro->id,
                'tipo' => $item['tipo'],
                'tiene_foto' => $fotoPath !== null,
            ]);

            $registradas[] = array_merge($item, [
                'id' => $registro->id,
                'estado' => $estado,
                'foto_guardada' => $fotoPath !== null,
            ]);
        }

        Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        Log::info("✅ Sincronización completada:", [
            'registradas' => count($registradas),
            'omitidas' => count($omitidas),
        ]);
        Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        return response()->json([
            'message' => 'Sincronización completada',
            'sincronizadas' => count($registradas),
            'omitidas' => count($omitidas),
            'detalles_omitidas' => $omitidas,
            'detalles_registradas'=> $registradas,
        ]);
    }
}