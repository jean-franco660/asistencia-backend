<?php

namespace App\Policies;

use App\Models\UsuarioWeb;   // usuario del panel (admin o director)
use App\Models\UsuarioApp;   // docente
use Illuminate\Auth\Access\HandlesAuthorization;

class UsuarioAppPolicy
{
    use HandlesAuthorization;

    /**
     * Antes de cualquier chequeo:
     * Si es admin, acceso total
     */
    public function before(UsuarioWeb $user, $ability)
    {
        if ($user->rol === 'admin') {
            return true;
        }
    }

    /**
     * Ver docente
     */
    public function view(UsuarioWeb $user, UsuarioApp $docente): bool
    {
        return $user->instituciones->pluck('id')
            ->intersect($docente->instituciones->pluck('id'))
            ->isNotEmpty();
    }

    /**
     * Actualizar docente
     */
    public function update(UsuarioWeb $user, UsuarioApp $docente): bool
    {
        return $user->instituciones->pluck('id')
            ->intersect($docente->instituciones->pluck('id'))
            ->isNotEmpty();
    }

    /**
     * Eliminar docente
     */
    public function delete(UsuarioWeb $user, UsuarioApp $docente): bool
    {
        return $user->instituciones->pluck('id')
            ->intersect($docente->instituciones->pluck('id'))
            ->isNotEmpty();
    }
}
