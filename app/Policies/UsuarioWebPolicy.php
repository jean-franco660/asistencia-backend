<?php

namespace App\Policies;

use App\Models\UsuarioWeb;
use Illuminate\Auth\Access\HandlesAuthorization;

class UsuarioWebPolicy
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
        return $user->rol === 'admin';
    }

    public function view(UsuarioWeb $user, UsuarioWeb $target)
    {
        return $user->rol === 'admin' || $user->id === $target->id;
    }

    public function create(UsuarioWeb $user)
    {
        return $user->rol === 'admin';
    }

    public function update(UsuarioWeb $user, UsuarioWeb $target)
    {
        return $user->rol === 'admin';
    }

    public function delete(UsuarioWeb $user, UsuarioWeb $target)
    {
        return $user->rol === 'admin';
    }
}
