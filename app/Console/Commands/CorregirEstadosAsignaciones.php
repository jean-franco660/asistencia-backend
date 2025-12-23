<?php

namespace App\Console\Commands;

use App\Models\UsuarioAppInstitucion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CorregirEstadosAsignaciones extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'asignaciones:corregir-estados';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Corrige el estado de asignaciones sin horario a INACTIVO';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Buscando asignaciones sin horario...');

        // Buscar asignaciones ACTIVAS sin horario
        $asignaciones = UsuarioAppInstitucion::where('estado', UsuarioAppInstitucion::ESTADO_ACTIVO)
            ->whereNull('horario_institucion_id')
            ->get();

        $total = $asignaciones->count();

        if ($total === 0) {
            $this->info('✅ No se encontraron asignaciones activas sin horario.');
            return 0;
        }

        $this->warn("⚠️  Se encontraron {$total} asignaciones activas sin horario.");

        if (!$this->confirm('¿Desea cambiar su estado a INACTIVO?', true)) {
            $this->info('Operación cancelada.');
            return 0;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $actualizados = 0;

        DB::transaction(function () use ($asignaciones, $bar, &$actualizados) {
            foreach ($asignaciones as $asignacion) {
                $asignacion->update([
                    'estado' => UsuarioAppInstitucion::ESTADO_INACTIVO
                ]);
                $actualizados++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Se actualizaron {$actualizados} asignaciones a estado INACTIVO.");

        return 0;
    }
}
