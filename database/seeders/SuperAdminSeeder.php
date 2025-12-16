<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\UsuarioWeb;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'superadmin@sistema.com';

        UsuarioWeb::updateOrCreate(
            ['email' => $email],
            [
                'nombre' => 'Super Administrador',
                'password' => 'SuperAdmin@123',
                'rol' => UsuarioWeb::ROL_SUPER_ADMIN,
                'estado' => 'autorizado',
            ]
        );
    }
}
