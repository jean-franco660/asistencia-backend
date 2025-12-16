<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\UsuarioWeb;
use App\Models\Institucion;

class SupervisorInstitucionTest extends TestCase
{
    /** @test */
    public function test_supervisor_puede_tener_multiples_instituciones()
    {
        $supervisor = $this->createUsuarioWeb(['rol' => 'supervisor']);
        $inst1 = $this->createInstitucion();
        $inst2 = $this->createInstitucion();
        $inst3 = $this->createInstitucion();

        $supervisor->instituciones()->attach([$inst1->id, $inst2->id, $inst3->id]);

        $this->assertCount(3, $supervisor->instituciones);
    }

    /** @test */
    public function test_sync_instituciones_actualiza_correctamente()
    {
        $supervisor = $this->createUsuarioWeb(['rol' => 'supervisor']);
        $inst1 = $this->createInstitucion();
        $inst2 = $this->createInstitucion();
        $inst3 = $this->createInstitucion();

        $supervisor->instituciones()->sync([$inst1->id, $inst2->id]);
        $this->assertCount(2, $supervisor->fresh()->instituciones);

        $supervisor->instituciones()->sync([$inst2->id, $inst3->id]);
        $supervisor->refresh();

        $this->assertCount(2, $supervisor->instituciones);
        $this->assertTrue($supervisor->instituciones->contains($inst2));
        $this->assertTrue($supervisor->instituciones->contains($inst3));
        $this->assertFalse($supervisor->instituciones->contains($inst1));
    }

    /** @test */
    public function test_eliminar_supervisor_no_elimina_institucion()
    {
        $supervisor = $this->createUsuarioWeb(['rol' => 'supervisor']);
        $institucion = $this->createInstitucion();

        $supervisor->instituciones()->attach($institucion->id);
        $supervisorId = $supervisor->id;
        $institucionId = $institucion->id;

        $supervisor->delete();

        $this->assertDatabaseHas('instituciones', [
            'id' => $institucionId,
        ]);

        $this->assertDatabaseMissing('supervisor_institucion', [
            'usuario_web_id' => $supervisorId,
            'institucion_id' => $institucionId,
        ]);
    }
}
