<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\HorarioInstitucion;
use App\Models\Institucion;

class HorarioInstitucionTest extends TestCase
{
    /** @test */
    public function test_crear_horario_requiere_institucion_id()
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/web/horarios', [
            'nombre_turno' => 'Mañana',
            'hora_entrada' => '08:00',
            'hora_salida' => '13:00',
            'tolerancia_minutos' => 15,
            'dias_semana' => ['L', 'M', 'X', 'J', 'V'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['institucion_id']);
    }

    /** @test */
    public function test_crear_horario_exitoso()
    {
        $this->actingAsAdmin();
        $institucion = $this->createInstitucion();

        $response = $this->postJson('/api/v1/web/horarios', [
            'institucion_id' => $institucion->id,
            'nombre_turno' => 'Mañana',
            'hora_entrada' => '08:00',
            'hora_salida' => '13:00',
            'tolerancia_minutos' => 15,
            'dias_semana' => ['L', 'M', 'X', 'J', 'V'],
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('horarios_institucion', [
            'institucion_id' => $institucion->id,
            'nombre_turno' => 'Mañana',
        ]);
    }

    /** @test */
    public function test_director_puede_crear_horario_en_su_institucion()
    {
        $director = $this->createUsuarioWeb(['rol' => 'director', 'estado' => 'autorizado']);
        $institucion = $this->createInstitucion();
        $director->instituciones()->attach($institucion->id);

        $response = $this->actingAs($director, 'sanctum')
            ->postJson('/api/v1/web/horarios', [
                'institucion_id' => $institucion->id,
                'nombre_turno' => 'Tarde',
                'hora_entrada' => '14:00',
                'hora_salida' => '19:00',
                'tolerancia_minutos' => 10,
                'dias_semana' => ['L', 'M', 'X', 'J', 'V'],
            ]);

        $response->assertStatus(201);
    }

    /** @test */
    public function test_director_no_puede_crear_horario_en_institucion_no_asignada()
    {
        $director = $this->createUsuarioWeb(['rol' => 'director', 'estado' => 'autorizado']);
        $institucionAsignada = $this->createInstitucion();
        $institucionNoAsignada = $this->createInstitucion();

        $director->instituciones()->attach($institucionAsignada->id);

        $response = $this->actingAs($director, 'sanctum')
            ->postJson('/api/v1/web/horarios', [
                'institucion_id' => $institucionNoAsignada->id,
                'nombre_turno' => 'Noche',
                'hora_entrada' => '19:00',
                'hora_salida' => '22:00',
                'tolerancia_minutos' => 5,
                'dias_semana' => ['L', 'M', 'X'],
            ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function test_listar_horarios_filtrados_por_institucion()
    {
        $this->actingAsAdmin();
        $institucion1 = $this->createInstitucion();
        $institucion2 = $this->createInstitucion();

        $this->createHorario(['institucion_id' => $institucion1->id]);
        $this->createHorario(['institucion_id' => $institucion1->id]);
        $this->createHorario(['institucion_id' => $institucion2->id]);

        $response = $this->getJson("/api/v1/web/horarios?institucion_id={$institucion1->id}");

        $response->assertStatus(200);
        $data = $response->json();

        // Verificar que solo devuelve horarios de la institución 1
        $this->assertCount(2, $data);
    }

    /** @test */
    public function test_director_solo_ve_horarios_de_sus_instituciones()
    {
        $director = $this->createUsuarioWeb(['rol' => 'director', 'estado' => 'autorizado']);
        $institucionAsignada = $this->createInstitucion();
        $institucionNoAsignada = $this->createInstitucion();

        $director->instituciones()->attach($institucionAsignada->id);

        $this->createHorario(['institucion_id' => $institucionAsignada->id]);
        $this->createHorario(['institucion_id' => $institucionNoAsignada->id]);

        $response = $this->actingAs($director, 'sanctum')
            ->getJson('/api/v1/web/horarios');

        $response->assertStatus(200);
        $data = $response->json();

        // Solo debe ver 1 horario (el de su institución)
        $this->assertCount(1, $data);
    }

    /** @test */
    public function test_validacion_de_horas()
    {
        $this->actingAsAdmin();
        $institucion = $this->createInstitucion();

        $response = $this->postJson('/api/v1/web/horarios', [
            'institucion_id' => $institucion->id,
            'nombre_turno' => 'Mañana',
            'hora_entrada' => 'invalid',
            'hora_salida' => '13:00',
            'tolerancia_minutos' => 15,
            'dias_semana' => ['L', 'M'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['hora_entrada']);
    }
}
