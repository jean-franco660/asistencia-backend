<?php

namespace App\Policies;

use App\Models\UsuarioWeb;
use App\Models\Asistencia;
use Illuminate\Auth\Access\HandlesAuthorization;

class AsistenciaPolicy
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
     * Super admin y administrador tienen control total sobre todas las asistencias.
     * Este método se ejecuta ANTES que cualquier otro método de la policy.
     */
    public function before(UsuarioWeb $user, $ability)
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        // No retornar nada (null) permite continuar con las verificaciones normales
    }

    /**
     * Determina si el usuario puede ver la lista de asistencias.
     * - Super admin/administrador: pueden ver todas
     * - Supervisor: puede ver las de sus instituciones
     */
    public function viewAny(UsuarioWeb $user): bool
    {
        return in_array($user->rol, [
            UsuarioWeb::ROL_SUPER_ADMIN,
            UsuarioWeb::ROL_ADMIN,
            UsuarioWeb::ROL_SUPERVISOR,
        ]);
    }

    /**
     * Determina si el usuario puede ver una asistencia específica.
     * - Super admin/administrador: todas las asistencias
     * - Supervisor: solo si pertenece a una de sus instituciones
     */
    public function view(UsuarioWeb $user, Asistencia $asistencia): bool
    {
        // Verifica si el supervisor tiene asignada la institución de esta asistencia
        // Usa exists() para mejor rendimiento
        return $user->instituciones()
            ->where('instituciones.id', $asistencia->institucion_id)
            ->exists();
    }

    /**
     * Determina si el usuario puede crear asistencias.
     * Todos los roles autenticados pueden crear asistencias.
     */
    public function create(UsuarioWeb $user): bool
    {
        return true;
    }

    /**
     * Determina si el usuario puede actualizar una asistencia.
     * - Super admin/administrador: todas las asistencias
     * - Supervisor: solo si pertenece a una de sus instituciones
     */
    public function update(UsuarioWeb $user, Asistencia $asistencia): bool
    {
        return $user->instituciones()
            ->where('instituciones.id', $asistencia->institucion_id)
            ->exists();
    }

    /**
     * Determina si el usuario puede eliminar una asistencia.
     * - Super admin/administrador: pueden eliminar cualquier asistencia
     * - Supervisor: solo si pertenece a una de sus instituciones
     */
    public function delete(UsuarioWeb $user, Asistencia $asistencia): bool
    {
        return $user->instituciones()
            ->where('instituciones.id', $asistencia->institucion_id)
            ->exists();
    }

    /**
     * Determina si el usuario puede restaurar una asistencia eliminada.
     * Solo super admin y administrador.
     */
    public function restore(UsuarioWeb $user, Asistencia $asistencia): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Determina si el usuario puede eliminar permanentemente una asistencia.
     * Solo super admin.
     */
    public function forceDelete(UsuarioWeb $user, Asistencia $asistencia): bool
    {
        return $user->rol === UsuarioWeb::ROL_SUPER_ADMIN;
    }
}