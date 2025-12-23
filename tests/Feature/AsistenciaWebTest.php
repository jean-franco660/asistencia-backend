<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\UsuarioWeb;
use App\Models\UsuarioApp;
use App\Models\Institucion;
use App\Models\Asistencia;
use App\Models\AsistenciaDiaria;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class AsistenciaWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /** @test */
    public function supervisor_can_list_asistencias_diarias()
    {
        try {
            $this->withoutExceptionHandling();
            // 1. Arrange
            $supervisor = UsuarioWeb::factory()->create([
                'rol' => 'supervisor',
                'estado' => 'autorizado'
            ]);

            // Asignar institución al supervisor (manually attach with truly null dates)
            $institucion = Institucion::factory()->create();
            $supervisor->instituciones()->attach($institucion->id, [
                'fecha_inicio' => null,
                'fecha_fin' => null
            ]);

            // Crear una asistencia pasada
            $docente = UsuarioApp::factory()->create();
            $asistencia = Asistencia::create([
                'usuario_app_id' => $docente->id,
                'institucion_id' => $institucion->id,
                'fecha' => now()->format('Y-m-d'),
                'estado_diario' => 'PRESENTE'
            ]);

            $diaria = AsistenciaDiaria::create([
                'asistencia_id' => $asistencia->id,
                'tipo' => 'ENTRADA',
                'marcada_en' => now(),
                'latitud' => -12.0000000,
                'longitud' => -77.0000000,
                'dentro_rango' => true,
                'estado_marcacion' => 'VALIDA',
                'registrado_en' => 'APP_ONLINE'
            ]);

            // 2. Act
            $response = $this->actingAs($supervisor, 'sanctum')
                ->getJson('/api/v1/web/asistencias?institucion_id=' . $institucion->id);

            $response->assertStatus(200)
                ->assertJsonPath('success', true);

            // Dump response to debug
            file_put_contents(base_path('response_dump.txt'), json_encode($response->json(), JSON_PRETTY_PRINT));

            $responseData = $response->json('data');
            $this->assertIsArray($responseData);
            $this->assertArrayHasKey('data', $responseData);
            $marcaciones = $responseData['data'];
            $this->assertGreaterThan(0, count($marcaciones));
            $this->assertEquals($diaria->id, $marcaciones[0]['id']);
            $this->assertArrayHasKey('asistencia', $marcaciones[0]);
            $this->assertArrayHasKey('usuario', $marcaciones[0]['asistencia']);
        } catch (\Throwable $e) {
            file_put_contents(base_path('error_log.txt'), $e->getMessage() . "\n" . $e->getTraceAsString());
            throw $e;
        }
    }

    /** @test */
    public function supervisor_can_review_observation()
    {
        // $this->withoutExceptionHandling(); // Temporarily commented to see errors
        // 1. Arrange
        $supervisor = UsuarioWeb::factory()->create(['rol' => 'supervisor', 'estado' => 'autorizado']);
        $institucion = Institucion::factory()->create();
        $supervisor->instituciones()->attach($institucion->id, [
            'fecha_inicio' => null,
            'fecha_fin' => null
        ]);

        // Crear asistencia observada
        $docente = UsuarioApp::factory()->create();
        $asistencia = Asistencia::create([
            'usuario_app_id' => $docente->id,
            'institucion_id' => $institucion->id,
            'fecha' => now()->format('Y-m-d'),
            'estado_diario' => 'PRESENTE'
        ]);

        $diaria = AsistenciaDiaria::create([
            'asistencia_id' => $asistencia->id,
            'tipo' => 'ENTRADA',
            'marcada_en' => now(),
            'estado_marcacion' => 'OBSERVADA',
            'motivo' => 'FUERA_DE_HORARIO',
            'estado_revision' => 'PENDIENTE'
        ]);

        // 2. Act
        $response = $this->actingAs($supervisor, 'sanctum')
            ->putJson("/api/v1/web/asistencias/marcaciones/{$diaria->id}/review", [
                'estado_revision' => 'APROBADA',
                'observacion' => 'Justificación válida por correo'
            ]);

        // 3. Assert
        $response->assertStatus(200);

        $diaria->refresh();
        $this->assertEquals('APROBADA', $diaria->estado_revision);
        $this->assertEquals('Justificación válida por correo', $diaria->revision_observacion);
        $this->assertEquals($supervisor->id, $diaria->revisado_por_usuario_web_id);
    }
}
