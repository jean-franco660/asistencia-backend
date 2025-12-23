<?php

namespace App\Observers;

use App\Models\HorarioInstitucion;

class HorarioInstitucionObserver
{
    /**
     * Handle the HorarioInstitucion "created" event.
     * 
     * Cuando se crea un horario para una institución, automáticamente
     * lo asigna a todos los usuarios (UsuarioApp) de esa institución.
     * Esto activará el Observer de UsuarioAppInstitucion que cambiará
     * el estado a ACTIVO.
     */
    public function created(HorarioInstitucion $horarioInstitucion): void
    {
        \Log::info('🕐 Horario creado - Auto-asignando a usuarios', [
            'horario_id' => $horarioInstitucion->id,
            'institucion_id' => $horarioInstitucion->institucion_id,
            'turno' => $horarioInstitucion->nombre_turno,
        ]);

        // 1. Asignar a los que no tienen horario (Lógica Legacy - Update)
        $asignaciones = \App\Models\UsuarioAppInstitucion::where('institucion_id', $horarioInstitucion->institucion_id)
            ->whereNull('horario_institucion_id')
            ->get();

        $count = 0;
        foreach ($asignaciones as $asignacion) {
            $asignacion->horario_institucion_id = $horarioInstitucion->id;
            $asignacion->save();
            $count++;
        }

        // 2. NUEVO: Asignar a usuarios que YA tienen otros horarios (Multi-Turno - Create)
        // Obtener usuarios únicos de la institución que tienen horario diferente al nuevo
        $usersWithOtherSchedules = \App\Models\UsuarioAppInstitucion::where('institucion_id', $horarioInstitucion->institucion_id)
            ->whereNotNull('horario_institucion_id')
            ->where('horario_institucion_id', '!=', $horarioInstitucion->id)
            ->select('usuario_app_id', 'cargo') // Necesitamos el cargo para replicarlo
            ->get()
            ->unique('usuario_app_id');

        foreach ($usersWithOtherSchedules as $existingAssignment) {
            // Verificar si ya tiene ESTE horario específico (evitar duplicados)
            $alreadyHas = \App\Models\UsuarioAppInstitucion::where('usuario_app_id', $existingAssignment->usuario_app_id)
                ->where('institucion_id', $horarioInstitucion->institucion_id)
                ->where('horario_institucion_id', $horarioInstitucion->id)
                ->exists();

            if (!$alreadyHas) {
                // Crear nueva asignación replicando el cargo
                \App\Models\UsuarioAppInstitucion::create([
                    'usuario_app_id' => $existingAssignment->usuario_app_id,
                    'institucion_id' => $horarioInstitucion->institucion_id,
                    'horario_institucion_id' => $horarioInstitucion->id,
                    'cargo' => $existingAssignment->cargo,
                    'estado' => \App\Models\UsuarioAppInstitucion::ESTADO_ACTIVO,
                ]);
                $count++;
            }
        }

        \Log::info('✅ Horario auto-asignado a usuarios (Multi-Turno)', [
            'horario_id' => $horarioInstitucion->id,
            'usuarios_actualizados' => $count,
        ]);
    }

    /**
     * Handle the HorarioInstitucion "updated" event.
     */
    public function updated(HorarioInstitucion $horarioInstitucion): void
    {
        //
    }

    /**
     * Handle the HorarioInstitucion "deleted" event.
     */
    public function deleted(HorarioInstitucion $horarioInstitucion): void
    {
        //
    }

    /**
     * Handle the HorarioInstitucion "restored" event.
     */
    public function restored(HorarioInstitucion $horarioInstitucion): void
    {
        //
    }

    /**
     * Handle the HorarioInstitucion "force deleted" event.
     */
    public function forceDeleted(HorarioInstitucion $horarioInstitucion): void
    {
        //
    }
}
