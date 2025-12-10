<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HorarioInstitucion;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class HorariosInstitucionController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $query = HorarioInstitucion::query();

        // FILTRAR POR INSTITUCIÓN ESPECÍFICA (del selector)
        if ($request->has('institucion_id') && $request->institucion_id) {
            $query->where('institucion_id', $request->institucion_id);
            
            // Si es director, validar que tenga acceso a esa institución
            if ($request->user()->rol === 'director') {
                $instituciones = $request->user()->instituciones->pluck('id');
                if (!$instituciones->contains($request->institucion_id)) {
                    return response()->json(['error' => 'No autorizado'], 403);
                }
            }
        } else {
            //  SIN institucion_id: comportamiento por rol
            if ($request->user()->rol === 'director') {
                $instituciones = $request->user()->instituciones->pluck('id');
                
                // Si el director solo tiene UNA institución, filtrar por ella automáticamente
                if ($instituciones->count() === 1) {
                    $query->where('institucion_id', $instituciones->first());
                } else {
                    // Si tiene varias, mostrar todas sus instituciones
                    $query->whereIn('institucion_id', $instituciones);
                }
            }
            // Admin ve todo si no hay filtro
        }

        return $query->orderBy('hora_entrada')->get();
    }


    public function store(Request $request)
    {
        $request->validate([
            'institucion_id' => 'required|exists:instituciones,id',
            'nombre_turno' => 'required|string|max:50',
            'hora_entrada' => 'required|date_format:H:i',
            'hora_salida' => 'required|date_format:H:i|after:hora_entrada',
            'tolerancia_minutos' => 'required|integer|min:0|max:60',

            // ✅ días laborales
            'dias_semana' => 'required|array|min:1',
            'dias_semana.*' => 'in:L,M,X,J,V,S,D',
        ]);

        // Si es director → validar institución asignada
        if ($request->user()->rol === 'director') {
            if (! $request->user()->instituciones->pluck('id')->contains($request->institucion_id)) {
                return response()->json(['error' => 'No autorizado'], 403);
            }
        }


        // autorización por rol
        $this->authorize('create', HorarioInstitucion::class);

        $horario = HorarioInstitucion::create([
            'institucion_id' => $request->institucion_id,
            'nombre_turno' => $request->nombre_turno,
            'hora_entrada' => $request->hora_entrada,
            'hora_salida' => $request->hora_salida,
            'tolerancia_minutos' => $request->tolerancia_minutos,
            'dias_semana' => $request->dias_semana,
            'activo' => true,
        ]);

        return response()->json($horario, 201);
    }

    public function update(Request $request, $id)
    {
        $horario = HorarioInstitucion::findOrFail($id);

        // validar institución asignada al director
        if ($request->user()->rol === 'director') {
            if (! $request->user()->instituciones->pluck('id')->contains($horario->institucion_id)) {
                return response()->json(['error' => 'No autorizado'], 403);
            }
        }


        // policy
        $this->authorize('update', $horario);

        $request->validate([
            'nombre_turno' => 'sometimes|string|max:50',
            'hora_entrada' => 'sometimes|date_format:H:i',
            'hora_salida' => 'sometimes|date_format:H:i|after:hora_entrada',
            'tolerancia_minutos' => 'sometimes|integer|min:0|max:60',
            'activo' => 'sometimes|boolean',

            // ✅ actualización de días
            'dias_semana' => 'sometimes|array|min:1',
            'dias_semana.*' => 'in:L,M,X,J,V,S,D',
        ]);

        $horario->update($request->only([
            'nombre_turno',
            'hora_entrada',
            'hora_salida',
            'tolerancia_minutos',
            'dias_semana',
            'activo',
        ]));

        return response()->json($horario);
    }

    public function destroy(Request $request, $id)
    {
        $horario = HorarioInstitucion::findOrFail($id);

        // validar institución asignada al director
        if ($request->user()->rol === 'director') {
            if (! $request->user()->instituciones->pluck('id')->contains($horario->institucion_id)) {
                return response()->json(['error' => 'No autorizado'], 403);
            }
        }


        // policy
        $this->authorize('delete', $horario);

        $horario->delete();

        return response()->json(['message' => 'Horario eliminado']);
    }
}
