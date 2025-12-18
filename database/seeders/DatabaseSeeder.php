<?php

namespace Database\Seeders;

use App\Models\UsuarioWeb;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seeders deshabilitados temporalmente debido a problemas estructurales
        // SuperAdminSeeder y DevDatosSeeder requieren horario_institucion_id

        $this->call([
            SuperAdminSeeder::class,
        ]);

        // if (app()->environment('local')) {
        //     $this->call([
        //         DevDatosSeeder::class,
        //     ]);
        // }
    }

}