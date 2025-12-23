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
            'departamento' => 'Lima',
            'provincia' => 'Lima',
            'nivel_educativo' => 'Secundaria',
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
            'tolerancia_entrada_minutos' => 15,
            'tolerancia_salida_minutos' => 15,
            'dias_semana' => json_encode([$diaLetra]),
            'activo' => true
        ]);

        // Crear vínculo activo para el usuario
        DB::table('usuario_app_institucion')->insert([
            'usuario_app_id' => $user->id,
            'institucion_id' => $institucion->id,
            'horario_institucion_id' => DB::table('horarios_institucion')->first()->id,
            'cargo' => 'DOCENTE',
            'estado' => 'ACTIVO',
            'fecha_inicio' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Ubicación del usuario: EXACTAMENTE en la misma ubicación (dentro de rango)
        $latUser = -12.000000;
        $lonUser = -77.000000;

        $file = UploadedFile::fake()->image('selfie.jpg');
        $uuid = \Illuminate\Support\Str::uuid()->toString();

        // 2. Act
        $response = $this->actingAs($user, 'sanctum') // Asumiendo guard de app
            ->postJson('/api/v1/app/asistencia', [
                'institucion_id' => $institucion->id,
                'fecha_hora' => $hoy->format('Y-m-d 08:05:00'), // A tiempo
                'latitud' => $latUser,
                'longitud' => $lonUser,
                'tipo' => 'ENTRADA', // Case sensitive check? Validation says ENTRADA/SALIDA
                'archivo' => $file,
                'offline_uuid' => $uuid
            ]);

        if ($response->status() !== 201) {
            dump($response->json());
        }

        // 3. Assert
        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        // Header Check
        $asistencia = Asistencia::latest()->first();
        $this->assertEquals($user->id, $asistencia->usuario_app_id);
        $this->assertEquals('PRESENTE', $asistencia->estado_diario);
        $this->assertNotNull($asistencia->hora_entrada);

        // Detail Check
        $marcacion = \App\Models\AsistenciaDiaria::where('asistencia_id', $asistencia->id)->first();
        $this->assertNotNull($marcacion);
        $this->assertEquals('ENTRADA', $marcacion->tipo);
        $this->assertTrue((bool) $marcacion->dentro_rango);
        $this->assertEquals('VALIDA', $marcacion->estado_marcacion);

        // Validar que el archivo existe (en disk fake)
        // Note: Controller returns direct path, verifying existence
        // Clean path to remove storage/ prefix if present or verify strictly
        if ($marcacion->foto_url) {
            Storage::disk('public')->assertExists($marcacion->foto_url);
        }
    }

    /** @test */
    public function sync_movil_processes_multipart_batch_correctly()
    {
        $this->withoutExceptionHandling();
        // 1. Arrange
        $user = UsuarioApp::factory()->create();
        $institucion = Institucion::create([
            'codigo_modular_ie' => '9999999',
            'nombre' => 'Sync School',
            'distrito' => 'Lima',
            'latitud' => -10.000000,
            'longitud' => -70.000000,
            'radio' => 500,
            'departamento' => 'Lima',
            'provincia' => 'Lima',
            'nivel_educativo' => 'Secundaria',
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

        $horarioId = DB::table('horarios_institucion')->insertGetId([
            'institucion_id' => $institucion->id,
            'nombre_turno' => 'TARDE',
            'hora_entrada' => '13:00:00',
            'hora_salida' => '18:00:00',
            'tolerancia_entrada_minutos' => 10,
            'tolerancia_salida_minutos' => 10,
            'dias_semana' => json_encode([$diaLetra]),
            'activo' => true
        ]);

        // Vínculo activo
        DB::table('usuario_app_institucion')->insert([
            'usuario_app_id' => $user->id,
            'institucion_id' => $institucion->id,
            'horario_institucion_id' => $horarioId,
            'cargo' => 'DOCENTE',
            'estado' => 'ACTIVO',
            'fecha_inicio' => now()->subDay(),
        ]);

        $file1 = UploadedFile::fake()->image('sync1.jpg');
        $file2 = UploadedFile::fake()->image('sync2.jpg');
        $uuid1 = \Illuminate\Support\Str::uuid()->toString();

        // 2. Act - Nested arrays for multipart
        $payload = [
            'asistencias' => [
                [
                    'institucion_id' => $institucion->id,
                    'fecha_hora' => $hoy->format('Y-m-d 13:05:00'),
                    'latitud' => -10.000000,
                    'longitud' => -70.000000,
                    'tipo' => 'ENTRADA',
                    'archivo' => $file1,
                    'es_falta' => false,
                    'offline_uuid' => $uuid1,
                ],
                [
                    'institucion_id' => $institucion->id,
                    'fecha_hora' => $hoy->format('Y-m-d 13:06:00'),
                    'latitud' => -80.000000,
                    'longitud' => -80.000000,
                    'tipo' => 'ENTRADA',
                    'archivo' => $file2,
                    'es_falta' => false,
                    'offline_uuid' => \Illuminate\Support\Str::uuid()->toString(),
                ]
            ]
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/v1/app/asistencias/sincronizar', $payload, ['Accept' => 'application/json']);

        // 3. Assert
        $response->assertStatus(200);

        $registradas = $response->json('detalles_registradas');
        $this->assertCount(2, $registradas);

        // Validar Header created
        $header = Asistencia::where('usuario_app_id', $user->id)->first();
        $this->assertNotNull($header);

        // Validar Item 1 (Detail)
        $dbItem1 = \App\Models\AsistenciaDiaria::where('offline_uuid', $uuid1)->first();
        $this->assertEquals($header->id, $dbItem1->asistencia_id);
        $this->assertTrue((bool) $dbItem1->dentro_rango);

        // Validar Item 2 (Detail)
        $dbDetail2 = \App\Models\AsistenciaDiaria::find($registradas[1]['id']);
        $dbHeader2 = $dbDetail2->asistencia;

        $this->assertEquals($user->id, $dbHeader2->usuario_app_id, 'Backend debe usar ID del token');
        $this->assertEquals('TARDE', $dbHeader2->turno);

        $this->assertFalse((bool) $dbDetail2->dentro_rango, 'Debe ser false porque está lejos');
        $this->assertNotNull($dbDetail2->foto_url);
        Storage::disk('public')->assertExists($dbDetail2->foto_url);
    }
}
