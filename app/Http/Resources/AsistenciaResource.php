<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AsistenciaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'usuario_app_id' => $this->usuario_app_id,
            'institucion_id' => $this->institucion_id,
            'horario_institucion_id' => $this->horario_institucion_id,
            'fecha' => $this->fecha?->format('Y-m-d'),
            'estado_diario' => $this->estado_diario,
            'hora_entrada' => $this->hora_entrada,
            'hora_salida' => $this->hora_salida,
            'minutos_tardanza' => $this->minutos_tardanza,
            'observacion' => $this->observacion,
            
            // Atributos dinámicos calculados (antes en $appends)
            'situacion' => $this->situacion,
            'resultado' => $this->resultado,
            'latitud' => $this->latitud,
            'longitud' => $this->longitud,
            'dentro_rango' => $this->dentro_rango,
            'foto' => $this->foto,
            'turno' => $this->turno,

            // Relaciones
            'usuario' => $this->whenLoaded('usuario', function () {
                return [
                    'id' => $this->usuario->id,
                    'codigo_modular' => $this->usuario->codigo_modular,
                    'nombre_completo' => $this->usuario->nombre_completo,
                    'dni' => $this->usuario->dni,
                ];
            }),
            'institucion' => $this->whenLoaded('institucion', function () {
                return [
                    'id' => $this->institucion->id,
                    'nombre' => $this->institucion->nombre,
                    'codigo_modular_ie' => $this->institucion->codigo_modular_ie,
                ];
            }),
            'horario' => $this->whenLoaded('horario', function () {
                return [
                    'id' => $this->horario->id,
                    'nombre_turno' => $this->horario->nombre_turno,
                    'hora_entrada' => $this->horario->hora_entrada,
                    'hora_salida' => $this->horario->hora_salida,
                ];
            }),
            'marcaciones' => AsistenciaDiariaResource::collection($this->whenLoaded('marcaciones')),
            'marcaciones_pendientes' => $this->when(isset($this->marcaciones_pendientes), $this->marcaciones_pendientes),
            
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
