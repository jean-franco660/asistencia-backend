<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\UsuarioWeb;

class AuditLogPolicy
{
    /**
     * Determine si el usuario puede ver cualquier log de auditoría.
     */
    public function viewAny(UsuarioWeb $user): bool
    {
        // Solo super_admin puede ver logs de auditoría
        return $user->rol === 'super_admin';
    }

    /**
     * Determine si el usuario puede ver un log específico.
     */
    public function view(UsuarioWeb $user, AuditLog $auditLog): bool
    {
        return $user->rol === 'super_admin';
    }

    /**
     * Los logs de auditoría no se pueden crear manualmente
     */
    public function create(UsuarioWeb $user): bool
    {
        return false;
    }

    /**
     * Los logs de auditoría no se pueden actualizar
     */
    public function update(UsuarioWeb $user, AuditLog $auditLog): bool
    {
        return false;
    }

    /**
     * Los logs de auditoría no se pueden eliminar
     */
    public function delete(UsuarioWeb $user, AuditLog $auditLog): bool
    {
        return false;
    }
}