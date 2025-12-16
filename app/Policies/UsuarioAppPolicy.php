<?php

namespace App\Policies;

use App\Models\UsuarioWeb;   // usuario del panel (super_admin, administrador o supervisor)
use App\Models\UsuarioApp;   // docente/usuario de la app móvil
use Illuminate\Auth\Access\HandlesAuthorization;

class UsuarioAppPolicy
{
    use HandlesAuthorization;

    /**
     * Verifica si el usuario tiene permisos de administrador
     * (super_admin o administrador)
     */
    private function isAdmin(UsuarioWeb $user): bool
    {
        return in_array($user->rol, [
            UsuarioWeb::ROL_SUPER_ADMIN,
            UsuarioWeb::ROL_ADMIN,
        ]);
    }

    /**
     * Verifica si el supervisor tiene acceso al docente
     * (comparten al menos una institución)
     */
    private function hasAccessToDocente(UsuarioWeb $user, UsuarioApp $docente): bool
    {
        return $user->instituciones->pluck('id')
            ->intersect($docente->instituciones->pluck('id'))
            ->isNotEmpty();
    }

    /**
     * Super admin y administrador tienen control total
     * Este método se ejecuta antes que cualquier otro
     */
    public function before(UsuarioWeb $user, $ability)
    {
        if ($this->isAdmin($user)) {
            return true;
        }
    }

    /**
     * Determina si el usuario puede ver cualquier docente/usuario app
     */
    public function viewAny(UsuarioWeb $user): bool
    {
        // Todos los usuarios autenticados pueden ver la lista
        return true;
    }

    /**
     * Determina si el usuario puede ver un docente específico
     * Super admin/administrador: todos
     * Supervisor: solo docentes de sus instituciones
     */
    public function view(UsuarioWeb $user, UsuarioApp $docente): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->hasAccessToDocente($user, $docente);
    }

    /**
     * Determina si el usuario puede crear docentes/usuarios app
     * Todos los roles pueden crear
     */
    public function create(UsuarioWeb $user): bool
    {
        return in_array($user->rol, [
            UsuarioWeb::ROL_SUPER_ADMIN,
            UsuarioWeb::ROL_ADMIN,
            UsuarioWeb::ROL_SUPERVISOR,
        ]);
    }

    /**
     * Determina si el usuario puede actualizar un docente
     * Super admin/administrador: todos
     * Supervisor: solo docentes de sus instituciones
     */
    public function update(UsuarioWeb $user, UsuarioApp $docente): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->hasAccessToDocente($user, $docente);
    }

    /**
     * Determina si el usuario puede eliminar un docente
     * Super admin/administrador: todos
     * Supervisor: solo docentes de sus instituciones
     */
    public function delete(UsuarioWeb $user, UsuarioApp $docente): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->hasAccessToDocente($user, $docente);
    }

    /**
     * Determina si el usuario puede restaurar un docente eliminado
     * Solo super admin y administrador
     */
    public function restore(UsuarioWeb $user, UsuarioApp $docente): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Determina si el usuario puede eliminar permanentemente un docente
     * Solo super admin
     */
    public function forceDelete(UsuarioWeb $user, UsuarioApp $docente): bool
    {
        return $user->rol === UsuarioWeb::ROL_SUPER_ADMIN;
    }

    /**
     * Determina si el usuario puede asignar instituciones a un docente
     * Super admin/administrador: pueden asignar cualquier institución
     * Supervisor: solo puede asignar sus propias instituciones
     */
    public function assignInstituciones(UsuarioWeb $user, UsuarioApp $docente): bool
    {
        return in_array($user->rol, [
            UsuarioWeb::ROL_SUPER_ADMIN,
            UsuarioWeb::ROL_ADMIN,
            UsuarioWeb::ROL_SUPERVISOR,
        ]);
    }

    /**
     * Determina si el usuario puede aprobar/rechazar solicitudes de registro
     * Solo super admin y administrador
     */
    public function approve(UsuarioWeb $user, UsuarioApp $docente): bool
    {
        return $this->isAdmin($user);
    }
}