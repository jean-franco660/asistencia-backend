<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        $diasMes = $finMes->day;

        for ($dia = 1; $dia <= $diasMes; $dia++) {

            $fecha = $inicioMes->copy()->day($dia);
            $labels[] = (string)$dia;

            // === Buscar ENTRADA ===
            $entrada = (clone $query)
                ->whereDate('fecha_hora', $fecha->toDateString())
                ->where('tipo', 'entrada')
                ->first();

            // === Buscar SALIDA ===
            $salida = (clone $query)
                ->whereDate('fecha_hora', $fecha->toDateString())
                ->where('tipo', 'salida')
                ->first();

            // === LÓGICA DE ASISTENCIA/FALTA ===
            if ($entrada && $salida) {
                $asistencias[] = 1;   // presente
                $faltas[] = 0;
            } else {
                $asistencias[] = 0;
                $faltas[] = 1;       // falta
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


    public function syncMovil(Request $request)
    {
        $validated = $request->validate([
            'asistencias' => 'required|array|min:1',
            'asistencias.*.usuario_id' => 'required|integer|exists:usuarios_app,id',
            'asistencias.*.institucion_id' => 'required|integer|exists:instituciones,id',
            'asistencias.*.fecha_hora' => 'required|date',
            'asistencias.*.dentro_rango' => 'required|boolean',
            'asistencias.*.latitud' => 'required|numeric',
            'asistencias.*.longitud' => 'required|numeric',
            'asistencias.*.foto' => 'required|string',
            'asistencias.*.tipo' => 'required|in:entrada,salida',
            'asistencias.*.turno' => 'required|string',
            'asistencias.*.falta' => 'required|boolean',
        ]);

        $registradas = [];
        $omitidas = [];

        foreach ($validated['asistencias'] as $item) {

            $fecha = Carbon::parse($item['fecha_hora']);

            // Feriado nacional
            $fn = Feriado::where('tipo', 'nacional')
                         ->where('dia', $fecha->day)
                         ->where('mes', $fecha->month)
                         ->where('activo', true)
                         ->first();
            
            if ($fn) {
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

            // Guardar selfie
            $fileName = 'selfies/' . uniqid('selfie_') . '.jpg';
            Storage::disk('public')->put($fileName, base64_decode($item['foto']));
            $item['foto'] = $fileName;

            // Registrar
            $registro = Asistencia::create(array_merge($item, [
                'sincronizado' => true,
                'estado' => $estado
            ]));

            $registradas[] = array_merge($item, [
                'id' => $registro->id,
                'estado' => $estado
            ]);
        }

        return response()->json([
            'message' => 'Sincronización completada',
            'sincronizadas' => count($registradas),
            'omitidas' => count($omitidas),
            'detalles_omitidas' => $omitidas,
            'detalles_registradas'=> $registradas,
        ]);
    }
}