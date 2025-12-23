<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AsistenciaMaterializationService;

class MaterializarFaltasCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'asistencias:generar-faltas {--fecha= : Fecha específica YYYY-MM-DD}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Genera registros de FALTA para docentes con horario activo que no marcaron asistencia';

    protected $service;

    public function __construct(AsistenciaMaterializationService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $fecha = $this->option('fecha');

        $this->info("Iniciando materialización de faltas..." . ($fecha ? " Fecha: {$fecha}" : " (Hoy)"));

        try {
            $count = $this->service->materializarFaltas($fecha);
            $this->info("✅ Proceso completado. Se generaron {$count} registros de FALTA.");
        } catch (\Exception $e) {
            $this->error("❌ Error: " . $e->getMessage());
            \Log::error("Error en asistencias:generar-faltas: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
