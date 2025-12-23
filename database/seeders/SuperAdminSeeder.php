<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\UsuarioWeb;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        UsuarioWeb::updateOrCreate(
            ['email' => 'admin@asistencia.com'],
            [
                'nombre'   => 'Super Admin',
                // IMPORTANTE: tu mutator setPasswordAttribute() lo hashea
                'password' => 'SuperAdmin123',  
                'rol'      => UsuarioWeb::ROL_SUPER_ADMIN,

                // Opcional: tu booted()->creating ya lo autoriza si es super_admin
                // 'estado' => UsuarioWeb::ESTADO_AUTORIZADO,
            ]
        );
    }
}
