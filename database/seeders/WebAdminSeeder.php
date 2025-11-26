<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class WebAdminSeeder extends Seeder
{
    public function run()
    {
        // Verifica si ya existe un admin
        $existe = DB::table('usuarios_web')->where('rol', 'admin')->exists();

        if ($existe) {
            $this->command->info('Usuario web admin ya existe.');
            return;
        }

        // Crear usuario web admin
        DB::table('usuarios_web')->insert([
            'nombre' => 'Usuario Web',
            'email' => 'admin@web.com',
            'password' => Hash::make('admin123'), // Cambia la contraseña si quieres
            'rol' => 'admin',
            'remember_token' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command->info('Usuario web admin creado correctamente.');
    }
}
