<?php

namespace App\Policies;

use App\Models\UsuarioWeb;
use Illuminate\Auth\Access\HandlesAuthorization;

class UsuarioWebPolicy
{
    use HandlesAuthorization;

    public function before(UsuarioWeb $user, $ability)
    {
        // Super admin lo puede todo
        if ($user->rol === UsuarioWeb::ROL_SUPER_ADMIN) {
            return true;
        }

        return null;
    }

    public function viewAny(UsuarioWeb $user): bool
    {
        // Admin puede listar, pero SOLO supervisores (el filtro se refuerza en Controller)
        return $user->rol === UsuarioWeb::ROL_ADMIN;
    }

    public function view(UsuarioWeb $user, UsuarioWeb $target): bool
    {
        // Admin NO puede ver admins, super_admin, ni a sí mismo: SOLO supervisores
        return $user->rol === UsuarioWeb::ROL_ADMIN
            && $target->rol === UsuarioWeb::ROL_SUPERVISOR;
    }

    public function create(UsuarioWeb $user): bool
    {
        // Admin puede crear SOLO supervisores (se valida en controller/request)
        return $user->rol === UsuarioWeb::ROL_ADMIN;
    }

    public function update(UsuarioWeb $user, UsuarioWeb $target): bool
    {
        // Admin solo actualiza supervisores
        return $user->rol === UsuarioWeb::ROL_ADMIN
            && $target->rol === UsuarioWeb::ROL_SUPERVISOR;
    }

    public function delete(UsuarioWeb $user, UsuarioWeb $target): bool
    {
        // Admin solo elimina supervisores
        return $user->rol === UsuarioWeb::ROL_ADMIN
            && $target->rol === UsuarioWeb::ROL_SUPERVISOR;
    }

    public function autorizar(UsuarioWeb $user, UsuarioWeb $target): bool
    {
        // Admin autoriza solo supervisores
        return $user->rol === UsuarioWeb::ROL_ADMIN
            && $target->rol === UsuarioWeb::ROL_SUPERVISOR;
    }

    public function rechazar(UsuarioWeb $user, UsuarioWeb $target): bool
    {
        return $this->autorizar($user, $target);
    }
}
