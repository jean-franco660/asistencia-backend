<?php

namespace App\Policies;

use App\Models\HorarioInstitucion;
use App\Models\UsuarioWeb;

class HorarioInstitucionPolicy
{
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
     * Determina si el usuario puede ver cualquier horario
     */
    public function viewAny(UsuarioWeb $user): bool
    {
        return true;
    }

    /**
     * Determina si el usuario puede ver un horario específico
     */
    public function view(UsuarioWeb $user, HorarioInstitucion $horario): bool
    {
        // Super admin y administrador pueden ver todo
        if ($this->isAdmin($user)) {
            return true;
        }

        // Supervisor solo puede ver horarios de sus instituciones
        if ($user->rol === UsuarioWeb::ROL_SUPERVISOR) {
            return $user->instituciones->pluck('id')->contains($horario->institucion_id);
        }

        return false;
    }

    /**
     * Determina si el usuario puede crear horarios
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
     * Determina si el usuario puede actualizar un horario
     */
    public function update(UsuarioWeb $user, HorarioInstitucion $horario): bool
    {
        // Super admin y administrador pueden actualizar todo
        if ($this->isAdmin($user)) {
            return true;
        }

        // Supervisor solo puede actualizar horarios de sus instituciones
        if ($user->rol === UsuarioWeb::ROL_SUPERVISOR) {
            return $user->instituciones->pluck('id')->contains($horario->institucion_id);
        }

        return false;
    }

    /**
     * Determina si el usuario puede eliminar un horario
     */
    public function delete(UsuarioWeb $user, HorarioInstitucion $horario): bool
    {
        // Super admin y administrador pueden eliminar todo
        if ($this->isAdmin($user)) {
            return true;
        }

        // Supervisor solo puede eliminar horarios de sus instituciones
        if ($user->rol === UsuarioWeb::ROL_SUPERVISOR) {
            return $user->instituciones->pluck('id')->contains($horario->institucion_id);
        }

        return false;
    }

    /**
     * Determina si el usuario puede restaurar un horario eliminado
     */
    public function restore(UsuarioWeb $user, HorarioInstitucion $horario): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Determina si el usuario puede eliminar permanentemente un horario
     */
    public function forceDelete(UsuarioWeb $user, HorarioInstitucion $horario): bool
    {
        // Solo super admin puede eliminar permanentemente
        return $user->rol === UsuarioWeb::ROL_SUPER_ADMIN;
    }
}