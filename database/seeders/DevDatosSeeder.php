<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Institucion;
use App\Models\UsuarioApp;

/**
 * Seeder exclusivo para entorno de desarrollo local.
 * Inserta datos mínimos de prueba: una institución educativa ficticia,
 * un docente de ejemplo y la relación N:M entre ambos.
 * No debe ejecutarse en producción.
 */
class DevDatosSeeder extends Seeder
{
    public function run(): void
    {
        // Institución educativa ficticia usada como referencia en las pruebas de desarrollo
        $inst = Institucion::create([
            'codigo_modular_ie' => 'PRUEBA001',
            'nombre' => 'IE Prueba Seed',
            'nivel_educativo' => 'Primaria',
            'distrito' => 'Lima',
            'centro_poblado' => null,
            'direccion' => null,
            'latitud' => -12.0464,
            'longitud' => -77.0428,
            'radio' => 100,
            'logo' => null,
        ]);

        // Docente de prueba; el mutator del modelo hashea la contraseña automáticamente
        $doc = UsuarioApp::create([
            'codigo_modular' => '102030',
            'apellido_paterno' => 'Pérez',
            'apellido_materno' => 'Quispe',
            'nombres' => 'Juan Carlos',
            'sexo' => UsuarioApp::SEXO_MASCULINO,
            'acceso_habilitado' => true,
            'password' => 'Temp12345!',
        ]);

        // Asigna el docente a la institución mediante la tabla pivote usuario_app_institucion
        $doc->instituciones()->syncWithoutDetaching([
            $inst->id => [
                'estado' => 'ACTIVO',
                'fecha_inicio' => now()->toDateString(),
                'fecha_fin' => null,
            ],
        ]);
    }
}
