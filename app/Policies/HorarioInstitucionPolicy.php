<?php

namespace App\Policies;

use App\Models\HorarioInstitucion;
use App\Models\UsuarioWeb;

class HorarioInstitucionPolicy
{
    public function viewAny(UsuarioWeb $user): bool
    {
        return true;
    }

    public function view(UsuarioWeb $user, HorarioInstitucion $horario): bool
    {
        // Admin puede todo
        if ($user->rol === 'admin') {
            return true;
        }

        // Director solo horarios de sus instituciones
        return $user->instituciones->pluck('id')->contains($horario->institucion_id);
    }

    public function create(UsuarioWeb $user): bool
    {
        return in_array($user->rol, ['admin', 'director']);
    }

    public function update(UsuarioWeb $user, HorarioInstitucion $horario): bool
    {
        if ($user->rol === 'admin') {
            return true;
        }

        return $user->instituciones->pluck('id')->contains($horario->institucion_id);
    }

    public function delete(UsuarioWeb $user, HorarioInstitucion $horario): bool
    {
        if ($user->rol === 'admin') {
            return true;
        }

        return $user->instituciones->pluck('id')->contains($horario->institucion_id);
    }
}
