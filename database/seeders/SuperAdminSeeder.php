<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\UsuarioWeb;

/**
 * Seeder de producción que garantiza la existencia del usuario Super Administrador.
 * Utiliza updateOrCreate para ser idempotente: puede ejecutarse múltiples veces
 * sin duplicar el registro. Aplica en todos los entornos.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        UsuarioWeb::updateOrCreate(
            ['email' => 'admin@asistencia.com'],
            [
                'nombre' => 'Super Admin',
                // El mutator setPasswordAttribute() del modelo aplica el hash automáticamente
                'password' => 'SuperAdmin123',  
                'rol' => UsuarioWeb::ROL_SUPER_ADMIN,

                // El evento booted()->creating del modelo autoriza automáticamente a super_admin
                // 'estado' => UsuarioWeb::ESTADO_AUTORIZADO,
            ]
        );
    }
}
