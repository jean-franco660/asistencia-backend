<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\UsuarioAppInstitucion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UsuarioAppInstitucionController extends Controller
{
    /**
     * Inactivar una asignación con fecha_fin, motivo y observación
     */
    public function inactivar(Request $request, $id)
    {
        $validated = $request->validate([
            'fecha_fin' => 'required|date',
            'motivo' => 'required|string|max:255',
            'observacion' => 'nullable|string'
        ]);

        $asignacion = UsuarioAppInstitucion::findOrFail($id);

        // Validar que fecha_fin > fecha_inicio (si existe)
        if ($asignacion->fecha_inicio && $validated['fecha_fin'] <= $asignacion->fecha_inicio->format('Y-m-d')) {
            return response()->json([
                'success' => false,
                'message' => 'La fecha de fin debe ser posterior a la fecha de inicio'
            ], 422);
        }

        DB::transaction(function () use ($asignacion, $validated) {
            $asignacion->update([
                'fecha_fin' => $validated['fecha_fin']
            ]);

            // TODO: Registrar en auditoría cuando se implemente
            // AsignacionAudit::create([
            //     'usuario_app_institucion_id' => $asignacion->id,
            //     'usuario_web_id' => auth()->id(),
            //     'accion' => 'INACTIVAR',
            //     'fecha_fin_anterior' => $fechaFinAnterior,
            //     'fecha_fin_nueva' => $validated['fecha_fin'],
            //     'motivo' => $validated['motivo'],
            //     'observacion' => $validated['observacion']
            // ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Asignación inactivada correctamente',
            'data' => $asignacion->fresh()
        ]);
    }
}
