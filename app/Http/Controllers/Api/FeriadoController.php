<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feriado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FeriadoController extends Controller
{
    /**
     * LISTAR FERIADOS
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->rol, ['admin', 'director'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $query = Feriado::with('institucion')
            ->where('activo', true)
            ->orderBy('mes')
            ->orderBy('dia');

        if ($user->rol === 'director') {
            $ids = $user->instituciones->pluck('id');

            $query->where(function ($q) use ($ids) {
                $q->where('tipo', 'nacional')
                  ->orWhere(function ($q2) use ($ids) {
                      $q2->where('tipo', 'institucional')
                         ->whereIn('institucion_id', $ids);
                  });
            });
        }

        return $query->get();
    }

    /**
     * CREAR FERIADO
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->rol, ['admin', 'director'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $request->validate([
            'tipo' => 'required|in:nacional,institucional',
            'institucion_id' => 'required_if:tipo,institucional|nullable|exists:instituciones,id',
            'descripcion' => 'required|string|max:255',

            // Si envía fecha → no necesita día/mes
            'fecha' => 'nullable|date',
            'dia' => 'required_without:fecha|integer|min:1|max:31',
            'mes' => 'required_without:fecha|integer|min:1|max:12',
        ]);

        // Director solo puede crear feriados de su institución
        if ($request->tipo === 'institucional' && $user->rol === 'director') {
            if (!$user->instituciones->pluck('id')->contains($request->institucion_id)) {
                return response()->json(['message' => 'No autorizado'], 403);
            }
        }

        // Convertimos fecha si viene día/mes
        $fecha = $request->fecha ??
            now()->year . '-' . str_pad($request->mes, 2, '0', STR_PAD_LEFT) . '-' . str_pad($request->dia, 2, '0', STR_PAD_LEFT);

        // Validación de duplicado
        $existe = Feriado::where('tipo', $request->tipo)
            ->where('institucion_id', $request->institucion_id)
            ->where('dia', $request->dia)
            ->where('mes', $request->mes)
            ->exists();

        if ($existe) {
            return response()->json(['message' => 'Feriado ya existe'], 422);
        }

        return Feriado::create([
            'tipo' => $request->tipo,
            'institucion_id' => $request->institucion_id,
            'descripcion' => $request->descripcion,
            'dia' => $request->dia,
            'mes' => $request->mes,
            'fecha' => $fecha,
            'activo' => true,
        ]);
    }

    /**
     * ACTUALIZAR
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();

        if (!in_array($user->rol, ['admin', 'director'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $feriado = Feriado::findOrFail($id);

        if ($feriado->tipo === 'institucional' && $user->rol === 'director') {
            if (!$user->instituciones->pluck('id')->contains($feriado->institucion_id)) {
                return response()->json(['message' => 'No autorizado'], 403);
            }
        }

        $request->validate([
            'dia' => 'sometimes|integer|min:1|max:31',
            'mes' => 'sometimes|integer|min:1|max:12',
            'descripcion' => 'sometimes|string|max:255',
            'activo' => 'sometimes|boolean',
        ]);

        // Si cambia día/mes, validamos duplicado
        if ($request->has('dia') || $request->has('mes')) {
            $dia = $request->dia ?? $feriado->dia;
            $mes = $request->mes ?? $feriado->mes;

            $dup = Feriado::where('tipo', $feriado->tipo)
                ->where('institucion_id', $feriado->institucion_id)
                ->where('dia', $dia)
                ->where('mes', $mes)
                ->where('id', '!=', $feriado->id)
                ->exists();

            if ($dup) {
                return response()->json(['message' => 'Feriado ya existe'], 422);
            }
        }

        $feriado->update($request->all());

        return $feriado;
    }

    /**
     * ELIMINAR
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        if (!in_array($user->rol, ['admin', 'director'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $feriado = Feriado::findOrFail($id);

        if ($feriado->tipo === 'institucional' && $user->rol === 'director') {
            if (!$user->instituciones->pluck('id')->contains($feriado->institucion_id)) {
                return response()->json(['message' => 'No autorizado'], 403);
            }
        }

        $feriado->delete();

        return response()->json(['message' => 'Feriado eliminado']);
    }

    /**
     * IMPORTAR AUTOMÁTICAMENTE LOS FERIADOS NACIONALES
     */
    public function actualizarAutomatico(Request $request)
    {
        \Log::info("=== ACTUALIZAR AUTOMATICO: INICIO ===");

        // 1. Verificar rol
        if ($request->user()->rol !== 'admin') {
            \Log::warning("Intento no autorizado por usuario ID {$request->user()->id}");
            return response()->json(['message' => 'No autorizado'], 403);
        }

        try {
            $year = now()->year;
            $url = "https://date.nager.at/api/v3/PublicHolidays/{$year}/PE";

            \Log::info("Consultando API de feriados: {$url}");

            // Crear base de la petición
            $requestHttp = Http::timeout(30);

            // Si estamos en LOCAL (Windows) desactivar SSL
            if (app()->environment('local')) {
                $requestHttp = $requestHttp->withoutVerifying();
            }

            // Ejecutar la petición
            $response = $requestHttp->get($url);

            \Log::info("Respuesta API Nager: Status " . $response->status());

            if (!$response->successful()) {
                \Log::error("API externa falló", [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return response()->json(['message' => 'Error API externa'], 500);
            }

            $json = $response->json();

            \Log::info("Cantidad de feriados recibidos: " . count($json));

            foreach ($json as $f) {

                \Log::info("Procesando feriado:", $f);

                $dia = intval(date('d', strtotime($f['date'])));
                $mes = intval(date('m', strtotime($f['date'])));

                \Log::info("Insertando/Actualizando:", [
                    'dia' => $dia,
                    'mes' => $mes,
                    'descripcion' => $f['localName'],
                    'fecha_original' => $f['date']
                ]);

                try {
                    Feriado::updateOrCreate(
                        [
                            'tipo' => 'nacional',
                            'dia' => $dia,
                            'mes' => $mes,
                            'institucion_id' => null
                        ],
                        [
                            'descripcion' => $f['localName'],
                            'fecha' => $f['date'],
                            'activo' => true,
                        ]
                    );
                } catch (\Exception $sqlError) {
                    \Log::error("Error SQL durante updateOrCreate", [
                        'error' => $sqlError->getMessage(),
                        'line' => $sqlError->getLine(),
                        'file' => $sqlError->getFile(),
                        'feriado' => $f
                    ]);

                    return response()->json([
                        'message' => 'Error SQL al guardar feriado',
                        'error' => $sqlError->getMessage()
                    ], 500);
                }
            }

            \Log::info("=== ACTUALIZAR AUTOMATICO: COMPLETADO ===");

            return response()->json(['message' => 'Feriados actualizados']);

        } catch (\Exception $e) {

            \Log::error("ERROR GENERAL en actualizarAutomatico", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return response()->json([
                'message' => 'Error al actualizar',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
