<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Institucion;
use App\Models\UsuarioApp;

class DevDatosSeeder extends Seeder
{
    public function run(): void
    {
        // =========================================================
        // 1) Institución
        // =========================================================
        $inst = Institucion::create([
            'codigo_modular_ie' => 'PRUEBA001',
            'nombre'            => 'IE Prueba Seed',
            'nivel_educativo'   => 'Primaria',
            'distrito'          => 'Lima',
            'centro_poblado'    => null,
            'direccion'         => null,
            'latitud'           => -12.0464,
            'longitud'          => -77.0428,
            'radio'             => 100,
            'logo'              => null,
        ]);

        // =========================================================
        // 2) Docente (usuarios_app)
        // NOTA: tu modelo hashea automáticamente "password"
        // =========================================================
        $doc = UsuarioApp::create([
            'codigo_modular_docente' => '102030',
            'apellido_paterno'       => 'Pérez',
            'apellido_materno'       => 'Quispe',
            'nombres'                => 'Juan Carlos',
            'sexo'                   => 'M',
            'cargo'                  => 'Docente',
            'password'               => 'Temp12345!',
            'estado'                 => 'ACTIVO',
            'activo'                 => 1,

            // Si quieres mantener "principal/legacy":
            // 'institucion_id'      => $inst->id,
        ]);

        // =========================================================
        // 3) Relación N:M (pivote docente_institucion)
        //    - tu pivote tiene estado + fechas + timestamps
        // =========================================================
        $doc->instituciones()->syncWithoutDetaching([
            $inst->id => [
                'estado'       => 'ACTIVO',
                'fecha_inicio' => now()->toDateString(),
                'fecha_fin'    => null,
            ],
        ]);
    }
}
