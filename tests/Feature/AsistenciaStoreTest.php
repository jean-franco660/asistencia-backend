<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\UsuarioApp;
use App\Models\Institucion;
use App\Models\Asistencia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AsistenciaStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('s3'); // Fake S3 too just in case
    }



    /** @test */
    public function store_validates_multipart_and_calculates_backend_fields()
    {
        // 1. Arrange
        $user = UsuarioApp::factory()->create();

        // Crear institución en una ubicación conocida
        $institucion = Institucion::create([
            'codigo_modular_ie' => '1234567',
            'nombre' => 'Test School',
            'distrito' => 'Lima',
            'latitud' => -12.000000,
            'longitud' => -77.000000,
            'radio' => 100, // 100 metros
        ]);

        // Crear horario para hoy a esta institución
        // Asumimos que el test corre hoy, forzamos un día laborable
        $hoy = Carbon::now();
        $diaSemana = strtolower($hoy->englishDayOfWeek); // monday, tuesday...

        // Mapeo simple para el DB insert
        $diaMap = [
            'monday' => 'L',
            'tuesday' => 'M',
            'wednesday' => 'X',
            'thursday' => 'J',
            'friday' => 'V',
            'saturday' => 'S',
            'sunday' => 'D'
        ];
        $diaLetra = $diaMap[$diaSemana];

        DB::table('horarios_institucion')->insert([
            'institucion_id' => $institucion->id,
            'nombre_turno' => 'MAÑANA',
            'hora_entrada' => '08:00:00',
            'hora_salida' => '13:00:00',
            'tolerancia_minutos' => 15,
            'dias_semana' => json_encode([$diaLetra]),
            'activo' => true
        ]);

        // Ubicación del usuario: EXACTAMENTE en la misma ubicación (dentro de rango)
        $latUser = -12.000000;
        $lonUser = -77.000000;

        $file = UploadedFile::fake()->image('selfie.jpg');

        // 2. Act
        $response = $this->actingAs($user, 'sanctum') // Asumiendo guard de app
            ->postJson('/api/v1/app/asistencia', [
                'institucion_id' => $institucion->id,
                'fecha_hora' => $hoy->format('Y-m-d 08:05:00'), // A tiempo
                'latitud' => $latUser,
                'longitud' => $lonUser,
                'tipo' => 'entrada',
                // Enviamos campos que deberían ser ignorados
                'usuario_app_id' => 999,
                'dentro_rango' => false, // Enviamos false, debe calcular true
                'turno' => 'Noche', // Enviamos Noche, debe ser Mañana
                'archivo' => $file
            ]);

        if ($response->status() !== 201) {
            dump($response->json());
        }

        // 3. Assert
        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $asistencia = Asistencia::latest()->first();

        // Validar autoridad de backend
        $this->assertEquals($user->id, $asistencia->usuario_app_id, 'El usuario ID debe ser del token');
        $this->assertEquals('MAÑANA', $asistencia->turno, 'El turno debe ser calculado del horario');
        $this->assertTrue((bool) $asistencia->dentro_rango, 'Debe estar dentro de rango calculado');
        $this->assertEquals('a_tiempo', $asistencia->estado);
        $this->assertNotNull($asistencia->foto);

        // Validar que el archivo existe (en disk fake)
        Storage::disk('public')->assertExists($asistencia->foto);
    }

    /** @test */
    public function sync_movil_processes_multipart_batch_correctly()
    {
        // 1. Arrange
        $user = UsuarioApp::factory()->create();
        $institucion = Institucion::create([
            'codigo_modular_ie' => '9999999',
            'nombre' => 'Sync School',
            'distrito' => 'Lima',
            'latitud' => -10.000000,
            'longitud' => -70.000000,
            'radio' => 500,
        ]);

        $hoy = Carbon::now();
        $diaSemana = strtolower($hoy->englishDayOfWeek);
        $diaMap = [
            'monday' => 'L',
            'tuesday' => 'M',
            'wednesday' => 'X',
            'thursday' => 'J',
            'friday' => 'V',
            'saturday' => 'S',
            'sunday' => 'D'
        ];
        $diaLetra = $diaMap[$diaSemana];

        DB::table('horarios_institucion')->insert([
            'institucion_id' => $institucion->id,
            'nombre_turno' => 'TARDE',
            'hora_entrada' => '13:00:00',
            'hora_salida' => '18:00:00',
            'tolerancia_minutos' => 10,
            'dias_semana' => json_encode([$diaLetra]),
            'activo' => true
        ]);

        $file1 = UploadedFile::fake()->image('sync1.jpg');
        $file2 = UploadedFile::fake()->image('sync2.jpg');

        // 2. Act - Nested arrays for multipart
        // Laravel's post() handles nested arrays correctly
        // Note: usuario_app_id is NOT included - backend enforces from token
        $payload = [
            'asistencias' => [
                [
                    'institucion_id' => $institucion->id,
                    'fecha_hora' => $hoy->format('Y-m-d 13:05:00'),
                    'latitud' => -10.000000,
                    'longitud' => -70.000000,
                    'tipo' => 'entrada',
                    'archivo' => $file1,
                    'falta' => false,
                    'dentro_rango' => false,
                    'turno' => 'Fake',
                ],
                [
                    'institucion_id' => $institucion->id,
                    'fecha_hora' => $hoy->format('Y-m-d 13:06:00'), // Different timestamp to avoid UNIQUE constraint
                    'latitud' => -80.000000,
                    'longitud' => -80.000000,
                    'tipo' => 'entrada',
                    'archivo' => $file2,
                    'falta' => false,
                    'dentro_rango' => true,
                    'turno' => 'Fake',
                ]
            ]
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/v1/app/asistencias/sincronizar', $payload, ['Accept' => 'application/json']);

        // 3. Assert
        $response->assertStatus(200);

        $registradas = $response->json('detalles_registradas');
        $this->assertCount(2, $registradas);

        // Validar Item 1
        $dbItem1 = Asistencia::find($registradas[0]['id']);
        $this->assertEquals($user->id, $dbItem1->usuario_app_id, 'Backend debe usar ID del token');
        $this->assertEquals('TARDE', $dbItem1->turno);
        $this->assertTrue((bool) $dbItem1->dentro_rango);
        $this->assertNotNull($dbItem1->foto);
        Storage::disk('public')->assertExists($dbItem1->foto);

        // Validar Item 2
        $dbItem2 = Asistencia::find($registradas[1]['id']);
        $this->assertEquals($user->id, $dbItem2->usuario_app_id, 'Backend debe usar ID del token');
        $this->assertEquals('TARDE', $dbItem2->turno);
        $this->assertFalse((bool) $dbItem2->dentro_rango, 'Debe ser false porque está lejos');
        $this->assertNotNull($dbItem2->foto);
        Storage::disk('public')->assertExists($dbItem2->foto);
    }
}
