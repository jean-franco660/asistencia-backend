<?php

namespace App\Policies;

use App\Models\UsuarioWeb;
use App\Models\Institucion;
use Illuminate\Auth\Access\HandlesAuthorization;

class InstitucionPolicy
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
            UsuarioWeb::ROL_ADMINISTRADOR,
        ]);
    }

    /**
     * Super admin y administrador tienen control total sobre todas las instituciones.
     * Este método se ejecuta ANTES que cualquier otro método de la policy.
     * Si retorna true, se omiten las demás verificaciones.
     */
    public function before(UsuarioWeb $user, $ability)
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        // No retornar nada (null) permite continuar con las verificaciones normales
    }

    /**
     * Determina si el usuario puede ver cualquier institución.
     * Todos los usuarios autenticados pueden ver la lista.
     */
    public function viewAny(UsuarioWeb $user): bool
    {
        return true;
    }

    /**
     * Determina si el usuario puede ver una institución específica.
     * - Super admin/administrador: todas las instituciones
     * - Supervisor: solo sus instituciones asignadas
     */
    public function view(UsuarioWeb $user, Institucion $institucion): bool
    {
        // Verifica si el supervisor tiene asignada esta institución
        // Usa exists() para evitar cargar todas las instituciones en memoria
        return $user->instituciones()->where('instituciones.id', $institucion->id)->exists();
    }

    /**
     * Determina si el usuario puede crear instituciones.
     * Solo super admin y administrador.
     */
    public function create(UsuarioWeb $user): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Determina si el usuario puede actualizar una institución.
     * - Super admin/administrador: todas las instituciones
     * - Supervisor: solo sus instituciones asignadas
     */
    public function update(UsuarioWeb $user, Institucion $institucion): bool
    {
        // Usa exists() para mejor rendimiento
        return $user->instituciones()->where('instituciones.id', $institucion->id)->exists();
    }

    /**
     * Determina si el usuario puede eliminar una institución.
     * Solo super admin y administrador.
     */
    public function delete(UsuarioWeb $user, Institucion $institucion): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Determina si el usuario puede restaurar una institución eliminada (soft delete).
     * Solo super admin y administrador.
     */
    public function restore(UsuarioWeb $user, Institucion $institucion): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Determina si el usuario puede eliminar permanentemente una institución.
     * Solo super admin puede realizar eliminaciones permanentes.
     */
    public function forceDelete(UsuarioWeb $user, Institucion $institucion): bool
    {
        return $user->rol === UsuarioWeb::ROL_SUPER_ADMIN;
    }

    /**
     * Determina si el usuario puede asignar supervisores a una institución.
     * Solo super admin y administrador.
     */
    public function assignSupervisor(UsuarioWeb $user, Institucion $institucion): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Determina si el usuario puede ver sus propias instituciones asignadas.
     * Todos los usuarios pueden ver sus instituciones.
     */
    public function viewOwn(UsuarioWeb $user): bool
    {
        return true;
    }
}