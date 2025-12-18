<?php

namespace App\Policies;

use App\Models\UsuarioWeb;
use Illuminate\Auth\Access\HandlesAuthorization;

class UsuarioWebPolicy
{
    use HandlesAuthorization;

    public function before(UsuarioWeb $user, $ability)
    {
        // Super admin puede hacer TODO
        if ($user->rol === 'super_admin') {  // ← String directo
            return true;
        }

        return null;
    }

    public function viewAny(UsuarioWeb $user): bool
    {
        return in_array($user->rol, ['super_admin', 'administrador']);  // ← String directo
    }

    public function view(UsuarioWeb $user, UsuarioWeb $target): bool
    {
        if ($user->rol === 'admin') {  // ← String directo
            return $target->rol === 'supervisor';
        }

        return false;
    }

    public function create(UsuarioWeb $user): bool
    {
        // ✅ SOLUCIÓN: Usar strings directos
        return in_array($user->rol, ['super_admin', 'administrador']);
    }

    public function update(UsuarioWeb $user, UsuarioWeb $target): bool
    {
        if ($user->rol === 'admin') {
            return $target->rol === 'supervisor';
        }

        return false;
    }

    public function delete(UsuarioWeb $user, UsuarioWeb $target): bool
    {
        if ($user->rol === 'admin') {
            return $target->rol === 'supervisor';
        }

        return false;
    }

    public function autorizar(UsuarioWeb $user, UsuarioWeb $target): bool
    {
        if ($user->rol === 'admin') {
            return $target->rol === 'supervisor';
        }

        return false;
    }

    public function rechazar(UsuarioWeb $user, UsuarioWeb $target): bool
    {
        return $this->autorizar($user, $target);
    }
}