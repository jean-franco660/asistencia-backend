<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\Feriado;
use App\Models\UsuarioWeb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FeriadoController extends Controller
{


    /**
     * LISTAR FERIADOS
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Validar que tenga permisos
        if (!$user->esAdminOSuperAdmin() && !$user->esSupervisor()) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $query = Feriado::with('institucion')
            ->where('activo', true)
            ->orderBy('mes')
            ->orderBy('dia');

        // Super admin y administrador ven todo
        if ($user->esAdminOSuperAdmin()) {
            return $query->get();
        }

        // Supervisor solo ve feriados nacionales + de sus instituciones vigentes
        if ($user->esSupervisor()) {
            $ids = $user->institucionesVigentes()->pluck('id');

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

        // Validar que tenga permisos
        if (!$user->esAdminOSuperAdmin() && !$user->esSupervisor()) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $request->validate([
            'tipo' => 'required|in:nacional,institucional',
            'institucion_id' => 'required_if:tipo,institucional|nullable|exists:instituciones,id',
            'descripcion' => 'required|string|max:255',
            'fecha' => 'nullable|date',
            'dia' => 'required_without:fecha|integer|min:1|max:31',
            'mes' => 'required_without:fecha|integer|min:1|max:12',
        ]);

        // Supervisor solo puede crear feriados institucionales de sus instituciones
        if ($request->tipo === 'institucional' && $user->esSupervisor()) {
            if (!$user->institucionesVigentes()->pluck('id')->contains($request->institucion_id)) {
                return response()->json(['message' => 'No autorizado para esta institución'], 403);
            }
        }

        // Supervisor NO puede crear feriados nacionales
        if ($request->tipo === 'nacional' && $user->esSupervisor()) {
            return response()->json(['message' => 'Solo administradores pueden crear feriados nacionales'], 403);
        }

        // Convertir fecha si viene día/mes
        $fecha = $request->fecha ?? 
            now()->year . '-' . str_pad($request->mes, 2, '0', STR_PAD_LEFT) . '-' . str_pad($request->dia, 2, '0', STR_PAD_LEFT);

        // Validar duplicado
        $existe = Feriado::where('tipo', $request->tipo)
            ->where('institucion_id', $request->institucion_id)
            ->where('dia', $request->dia)
            ->where('mes', $request->mes)
            ->exists();

        if ($existe) {
            return response()->json(['message' => 'Feriado ya existe'], 422);
        }

        $feriado = Feriado::create([
            'tipo' => $request->tipo,
            'institucion_id' => $request->institucion_id,
            'descripcion' => $request->descripcion,
            'dia' => $request->dia,
            'mes' => $request->mes,
            'fecha' => $fecha,
            'activo' => true,
        ]);

        return response()->json($feriado, 201);
    }

    /**
     * ACTUALIZAR FERIADO
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();

        // Validar que tenga permisos
        if (!$user->esAdminOSuperAdmin() && !$user->esSupervisor()) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $feriado = Feriado::findOrFail($id);

        // Supervisor solo puede actualizar feriados institucionales de sus instituciones
        if ($user->esSupervisor()) {
            if ($feriado->tipo === 'nacional') {
                return response()->json(['message' => 'No puede modificar feriados nacionales'], 403);
            }

            if (!$user->institucionesVigentes()->pluck('id')->contains($feriado->institucion_id)) {
                return response()->json(['message' => 'No autorizado para esta institución'], 403);
            }
        }

        $request->validate([
            'dia' => 'sometimes|integer|min:1|max:31',
            'mes' => 'sometimes|integer|min:1|max:12',
            'descripcion' => 'sometimes|string|max:255',
            'activo' => 'sometimes|boolean',
        ]);

        // Si cambia día/mes, validar duplicado
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
                return response()->json(['message' => 'Feriado ya existe con esa fecha'], 422);
            }
        }

        $feriado->update($request->only(['dia', 'mes', 'descripcion', 'activo']));

        return response()->json($feriado);
    }

    /**
     * ELIMINAR FERIADO
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        // Validar que tenga permisos
        if (!$user->esAdminOSuperAdmin() && !$user->esSupervisor()) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $feriado = Feriado::findOrFail($id);

        // Supervisor solo puede eliminar feriados institucionales de sus instituciones
        if ($user->esSupervisor()) {
            if ($feriado->tipo === 'nacional') {
                return response()->json(['message' => 'No puede eliminar feriados nacionales'], 403);
            }

            if (!$user->institucionesVigentes()->pluck('id')->contains($feriado->institucion_id)) {
                return response()->json(['message' => 'No autorizado para esta institución'], 403);
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
        Log::info("=== ACTUALIZAR AUTOMATICO: INICIO ===");

        $user = $request->user();

        // Solo super admin y administrador pueden actualizar automáticamente
        if (!$user->esAdminOSuperAdmin()) {
            Log::warning("Intento no autorizado por usuario ID {$user->id} con rol {$user->rol}");
            return response()->json(['message' => 'No autorizado. Solo administradores pueden actualizar feriados nacionales.'], 403);
        }

        try {
            $year = now()->year;
            $url = "https://date.nager.at/api/v3/PublicHolidays/{$year}/PE";

            Log::info("Consultando API de feriados: {$url}");

            // Crear base de la petición
            $requestHttp = Http::timeout(30);

            // Si estamos en LOCAL desactivar SSL
            if (app()->environment('local')) {
                $requestHttp = $requestHttp->withoutVerifying();
            }

            // Ejecutar la petición
            $response = $requestHttp->get($url);

            Log::info("Respuesta API Nager: Status " . $response->status());

            if (!$response->successful()) {
                Log::error("API externa falló", [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return response()->json(['message' => 'Error al consultar API externa'], 500);
            }

            $json = $response->json();

            Log::info("Cantidad de feriados recibidos: " . count($json));

            $procesados = 0;
            $errores = 0;

            foreach ($json as $f) {
                Log::info("Procesando feriado:", $f);

                $dia = intval(date('d', strtotime($f['date'])));
                $mes = intval(date('m', strtotime($f['date'])));

                Log::info("Insertando/Actualizando:", [
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
                    $procesados++;
                } catch (\Exception $sqlError) {
                    $errores++;
                    Log::error("Error SQL durante updateOrCreate", [
                        'error' => $sqlError->getMessage(),
                        'line' => $sqlError->getLine(),
                        'file' => $sqlError->getFile(),
                        'feriado' => $f
                    ]);
                }
            }

            Log::info("=== ACTUALIZAR AUTOMATICO: COMPLETADO ===", [
                'procesados' => $procesados,
                'errores' => $errores
            ]);

            return response()->json([
                'message' => 'Feriados actualizados',
                'procesados' => $procesados,
                'errores' => $errores,
                'total' => count($json)
            ]);

        } catch (\Exception $e) {
            Log::error("ERROR GENERAL en actualizarAutomatico", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return response()->json([
                'message' => 'Error al actualizar feriados',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}