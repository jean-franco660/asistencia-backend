<?php

namespace App\Traits;

use App\Models\UsuarioWeb;

trait ChecksAdminRole
{
    /**
     * Verifica si el usuario es super_admin o administrador
     * 
     * @param UsuarioWeb|null $user
     * @return bool
     */
    protected function esAdministrador($user): bool
    {
        if (!$user || !($user instanceof UsuarioWeb)) {
            return false;
        }

        return $user->esAdminOSuperAdmin();
    }

    /**
     * Verifica si el usuario es super_admin
     * 
     * @param UsuarioWeb|null $user
     * @return bool
     */
    protected function esSuperAdmin($user): bool
    {
        if (!$user || !($user instanceof UsuarioWeb)) {
            return false;
        }

        return $user->esSuperAdmin();
    }

    /**
     * Verifica si el usuario es supervisor
     * 
     * @param UsuarioWeb|null $user
     * @return bool
     */
    protected function esSupervisor($user): bool
    {
        if (!$user || !($user instanceof UsuarioWeb)) {
            return false;
        }

        return $user->esSupervisor();
    }

    /**
     * Obtiene los IDs de instituciones vigentes del usuario
     * 
     * @param UsuarioWeb $user
     * @return array
     */
    protected function getInstitucionesVigentesIds(UsuarioWeb $user): array
    {
        if ($user->esAdminOSuperAdmin()) {
            return []; // Admin ve todas, no necesita filtrar
        }

        return $user->getInstitucionesVigentesIds();
    }
}