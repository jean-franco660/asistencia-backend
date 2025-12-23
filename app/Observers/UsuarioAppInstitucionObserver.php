<?php

namespace App\Observers;

use App\Models\UsuarioAppInstitucion;
use Illuminate\Support\Facades\Log;

class UsuarioAppInstitucionObserver
{
    /**
     * Se ejecuta cuando se crea el registro
     */
    /**
     * Se ejecuta cuando se crea el registro
     */
    public function creating(UsuarioAppInstitucion $vinculo): void
    {
        // Si se crea con horario asignado → ACTIVO desde el inicio
        if ($vinculo->horario_institucion_id) {
            $vinculo->estado = UsuarioAppInstitucion::ESTADO_ACTIVO;

            // ✅ REGLA: Establecer fecha_inicio automáticamente
            if (!$vinculo->fecha_inicio) {
                $vinculo->fecha_inicio = today();

                Log::info('Asignación creada con horario - fecha_inicio establecida automáticamente', [
                    'usuario_app_id' => $vinculo->usuario_app_id,
                    'institucion_id' => $vinculo->institucion_id,
                    'fecha_inicio' => $vinculo->fecha_inicio,
                ]);
            }
        } else {
            // ✅ Si se crea SIN horario → PENDIENTE (en espera de horario)
            $vinculo->estado = UsuarioAppInstitucion::ESTADO_PENDIENTE;

            Log::info('Asignación creada sin horario - estado PENDIENTE', [
                'usuario_app_id' => $vinculo->usuario_app_id,
                'institucion_id' => $vinculo->institucion_id,
            ]);
        }
    }

    /**
     * Se ejecuta cuando se actualiza el registro
     */
    public function updating(UsuarioAppInstitucion $vinculo): void
    {
        // ✅ REGLA 1: Asignación de horario → Activación automática
        if ($vinculo->isDirty('horario_institucion_id') && $vinculo->horario_institucion_id) {

            // Cambiar a ACTIVO
            $vinculo->estado = UsuarioAppInstitucion::ESTADO_ACTIVO;

            // Establecer fecha_inicio solo si está vacía (primera activación)
            if (!$vinculo->fecha_inicio) {
                $vinculo->fecha_inicio = today();

                Log::info('Horario asignado - Activación automática con fecha_inicio', [
                    'usuario_app_id' => $vinculo->usuario_app_id,
                    'institucion_id' => $vinculo->institucion_id,
                    'horario_id' => $vinculo->horario_institucion_id,
                    'fecha_inicio' => $vinculo->fecha_inicio,
                ]);
            }

            // Si es una reactivación, limpiar fecha_fin
            if ($vinculo->fecha_fin) {
                $vinculo->fecha_fin = null;

                Log::info('Reactivación - fecha_fin limpiada', [
                    'usuario_app_id' => $vinculo->usuario_app_id,
                    'institucion_id' => $vinculo->institucion_id,
                ]);
            }
        }

        // ✅ REGLA 2: Cambio a INACTIVO (manual) → Inhabilitación automática
        if (
            $vinculo->isDirty('estado') &&
            $vinculo->estado === UsuarioAppInstitucion::ESTADO_INACTIVO &&
            !$vinculo->fecha_fin
        ) {

            $vinculo->fecha_fin = today();

            Log::info('Usuario inhabilitado - fecha_fin establecida automáticamente', [
                'usuario_app_id' => $vinculo->usuario_app_id,
                'institucion_id' => $vinculo->institucion_id,
                'fecha_fin' => $vinculo->fecha_fin,
                'triggered_by' => auth()->id() ?? 'system',
            ]);
        }

        // ✅ REGLA 3: Reactivación manual (cambio de INACTIVO a ACTIVO)
        if (
            $vinculo->isDirty('estado') &&
            $vinculo->estado === UsuarioAppInstitucion::ESTADO_ACTIVO &&
            $vinculo->getOriginal('estado') === UsuarioAppInstitucion::ESTADO_INACTIVO &&
            $vinculo->fecha_fin
        ) {

            $vinculo->fecha_fin = null;

            Log::info('Reactivación manual - fecha_fin limpiada', [
                'usuario_app_id' => $vinculo->usuario_app_id,
                'institucion_id' => $vinculo->institucion_id,
            ]);
        }

        // ✅ REGLA 4: Eliminación de horario → Vuelve a PENDIENTE
        if (
            $vinculo->isDirty('horario_institucion_id') &&
            is_null($vinculo->horario_institucion_id) &&
            $vinculo->estado === UsuarioAppInstitucion::ESTADO_ACTIVO
        ) {

            $vinculo->estado = UsuarioAppInstitucion::ESTADO_PENDIENTE;

            Log::info('Horario eliminado - Estado cambiado a PENDIENTE', [
                'usuario_app_id' => $vinculo->usuario_app_id,
                'institucion_id' => $vinculo->institucion_id,
            ]);
        }
    }
}