<?php

namespace Database\Seeders;

use App\Models\UsuarioWeb;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder principal que orquesta la ejecución de todos los seeders de la aplicación.
 * Actúa como punto de entrada para `php artisan db:seed`. Solo ejecuta
 * SuperAdminSeeder en todos los entornos; DevDatosSeeder está deshabilitado
 * mientras la estructura de horario_institucion_id se estabiliza.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // DevDatosSeeder está deshabilitado temporalmente: requiere que horario_institucion_id
        // exista en usuario_app_institucion antes de ejecutarse correctamente.

        $this->call([
            SuperAdminSeeder::class,
        ]);

        // if (app()->environment('local')) {
        // $this->call([
        // DevDatosSeeder::class,
        // ]);
        // }
    }

}