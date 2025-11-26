<?php

namespace App\Policies;

use App\Models\UsuarioWeb;
use App\Models\Asistencia;
use Illuminate\Auth\Access\HandlesAuthorization;

class AsistenciaPolicy
{
    use HandlesAuthorization;

    public function before(UsuarioWeb $user, $ability)
    {
        if ($user->rol === 'admin') {
            return true;
        }
    }

    public function viewAny(UsuarioWeb $user)
    {
        return $user->rol === 'director';
    }

    public function view(UsuarioWeb $user, Asistencia $asistencia)
    {
        return $user->instituciones->pluck('id')
            ->contains($asistencia->institucion_id);
    }
}
