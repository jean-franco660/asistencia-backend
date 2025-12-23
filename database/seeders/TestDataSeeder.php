<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $hoy = Carbon::today('America/Lima')->toDateString();

        // 1. Insertar Institución directamente
        DB::table('instituciones')->insert([
            'codigo_modular_ie' => 'IE001',
            'nombre' => 'Institución Educativa de Prueba',
            'nivel_educativo' => 'PRIMARIA',
            'tipo_gestion' => 'PUBLICA',
            'distrito' => 'LIMA',
            'latitud' => -12.046374,
            'longitud' => -77.042793,
            'radio' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $institucionId = DB::getPdo()->lastInsertId();

        // 2. Insertar Horario
        DB::table('horarios_institucion')->insert([
            'institucion_id' => $institucionId,
            'nombre_turno' => 'MAÑANA',
            'hora_entrada' => '08:00:00',
            'hora_salida' => '13:00:00',
            'tolerancia_entrada_minutos' => 15,
            'tolerancia_salida_minutos' => 15,
            'dias_semana' => json_encode(['L', 'M', 'X', 'J', 'V']),
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $horarioId = DB::getPdo()->lastInsertId();

        // 3. Insertar Docentes
        $docentes = [];
        for ($i = 1; $i <= 5; $i++) {
            DB::table('usuarios_app')->insert([
                'codigo_modular' => '1234567890' . $i,
                'dni' => '1000000' . str_pad($i, 2, '0', STR_PAD_LEFT),
                'apellido_paterno' => 'Apellido' . $i,
                'apellido_materno' => 'Materno' . $i,
                'nombres' => 'Docente' . $i,
                'sexo' => 'M',
                'telefono' => '90000000' . $i,
                'password' => Hash::make('password123'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $docentes[] = DB::getPdo()->lastInsertId();
        }

        // 4. Asignar docentes a institución
        foreach ($docentes as $index => $docenteId) {
            DB::table('usuario_app_institucion')->insert([
                'usuario_app_id' => $docenteId,
                'institucion_id' => $institucionId,
                'horario_institucion_id' => $horarioId,
                'cargo' => $index === 0 ? 'DIRECTOR' : 'DOCENTE',
                'fecha_inicio' => '2025-01-01',
                'fecha_fin' => null,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 5. Crear asistencias para HOY

        // Docente 1: PRESENTE
        DB::table('asistencias')->insert([
            'id' => 1,
            'usuario_app_id' => $docentes[0],
            'institucion_id' => $institucionId,
            'fecha' => $hoy,
            'horario_institucion_id' => $horarioId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('asistencias_diarias')->insert([
            'asistencia_id' => 1,
            'tipo' => 'ENTRADA',
            'marcada_en' => $hoy . ' 08:05:00',
            'latitud' => -12.046374,
            'longitud' => -77.042793,
            'estado_marcacion' => 'VALIDA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('asistencias_diarias')->insert([
            'asistencia_id' => 1,
            'tipo' => 'SALIDA',
            'marcada_en' => $hoy . ' 13:00:00',
            'latitud' => -12.046374,
            'longitud' => -77.042793,
            'estado_marcacion' => 'VALIDA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Docente 2: TARDANZA  
        DB::table('asistencias')->insert([
            'id' => 2,
            'usuario_app_id' => $docentes[1],
            'institucion_id' => $institucionId,
            'fecha' => $hoy,
            'horario_institucion_id' => $horarioId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('asistencias_diarias')->insert([
            'asistencia_id' => 2,
            'tipo' => 'ENTRADA',
            'marcada_en' => $hoy . ' 08:25:00',
            'latitud' => -12.046374,
            'longitud' => -77.042793,
            'estado_marcacion' => 'VALIDA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('asistencias_diarias')->insert([
            'asistencia_id' => 2,
            'tipo' => 'SALIDA',
            'marcada_en' => $hoy . ' 13:10:00',
            'latitud' => -12.046374,
            'longitud' => -77.042793,
            'estado_marcacion' => 'VALIDA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Docente 3: OBSERVADA
        DB::table('asistencias')->insert([
            'id' => 3,
            'usuario_app_id' => $docentes[2],
            'institucion_id' => $institucionId,
            'fecha' => $hoy,
            'horario_institucion_id' => $horarioId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('asistencias_diarias')->insert([
            'asistencia_id' => 3,
            'tipo' => 'ENTRADA',
            'marcada_en' => $hoy . ' 07:45:00',
            'latitud' => -12.050000,
            'longitud' => -77.050000,
            'estado_marcacion' => 'OBSERVADA',
            'motivo_observacion' => 'Fuera de rango GPS',
            'estado_revision' => 'PENDIENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('asistencias_diarias')->insert([
            'asistencia_id' => 3,
            'tipo' => 'SALIDA',
            'marcada_en' => $hoy . ' 13:05:00',
            'latitud' => -12.046374,
            'longitud' => -77.042793,
            'estado_marcacion' => 'VALIDA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Docentes 4 y 5 no tienen marcaciones (aparecerán como FALTA después del job)

        $this->command->info('✅ Datos de prueba creados con DB directo:');
        $this->command->info('   - 1 Institución: IE001');
        $this->command->info('   - 1 Horario: MAÑANA (L-V)');
        $this->command->info('   - 5 Docentes creados');
        $this->command->info('   - 3 Asistencias con marcaciones');
        $this->command->info('   - 2 Docentes sin marcaciones');
        $this->command->info('');
        $this->command->info('Ejecuta ahora:');
        $this->command->info('  php artisan asistencias:materializar ' . $hoy);
    }
}
