<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\UsuarioWeb;
use App\Models\Institucion;

class DirectorInstitucionTest extends TestCase
{
    /** @test */
    public function test_director_puede_tener_multiples_instituciones()
    {
        $director = $this->createUsuarioWeb(['rol' => 'director']);
        $inst1 = $this->createInstitucion();
        $inst2 = $this->createInstitucion();
        $inst3 = $this->createInstitucion();

        $director->instituciones()->attach([$inst1->id, $inst2->id, $inst3->id]);

        $this->assertCount(3, $director->instituciones);
    }

    /** @test */
    public function test_sync_instituciones_actualiza_correctamente()
    {
        $director = $this->createUsuarioWeb(['rol' => 'director']);
        $inst1 = $this->createInstitucion();
        $inst2 = $this->createInstitucion();
        $inst3 = $this->createInstitucion();

        $director->instituciones()->sync([$inst1->id, $inst2->id]);
        $this->assertCount(2, $director->fresh()->instituciones);

        $director->instituciones()->sync([$inst2->id, $inst3->id]);
        $director->refresh();

        $this->assertCount(2, $director->instituciones);
        $this->assertTrue($director->instituciones->contains($inst2));
        $this->assertTrue($director->instituciones->contains($inst3));
        $this->assertFalse($director->instituciones->contains($inst1));
    }

    /** @test */
    public function test_eliminar_director_no_elimina_institucion()
    {
        $director = $this->createUsuarioWeb(['rol' => 'director']);
        $institucion = $this->createInstitucion();

        $director->instituciones()->attach($institucion->id);
        $directorId = $director->id;
        $institucionId = $institucion->id;

        $director->delete();

        $this->assertDatabaseHas('instituciones', [
            'id' => $institucionId,
        ]);

        $this->assertDatabaseMissing('director_institucion', [
            'usuario_web_id' => $directorId,
            'institucion_id' => $institucionId,
        ]);
    }
}
