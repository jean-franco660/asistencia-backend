<?php

namespace App\Policies;

use App\Models\UsuarioWeb;
use App\Models\Institucion;
use Illuminate\Auth\Access\HandlesAuthorization;

class InstitucionPolicy
{
    use HandlesAuthorization;

    /**
     * Admin tiene control total
     */
    public function before($user, $ability)
    {
        if ($user instanceof UsuarioWeb && $user->rol === 'admin') {
            return true;
        }
    }

    /**
     * Director puede ver solo sus instituciones
     */
    public function view(UsuarioWeb $user, Institucion $institucion): bool
    {
        return $user->instituciones->contains($institucion);
    }

    /**
     * Director puede actualizar solo sus instituciones
     */
    public function update(UsuarioWeb $user, Institucion $institucion): bool
    {
        return $user->instituciones->contains($institucion);
    }

    /**
     * Director NO puede crear instituciones
     */
    public function create(UsuarioWeb $user): bool
    {
        return false;
    }

    /**
     * Director NO puede eliminar instituciones
     */
    public function delete(UsuarioWeb $user, Institucion $institucion): bool
    {
        return false;
    }
}
