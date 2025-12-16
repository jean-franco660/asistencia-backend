<?php

namespace App\Console\Commands;

use App\Models\ImportacionLog;
use Illuminate\Console\Command;

class MonitorImportacionesCommand extends Command
{
    protected $signature = 'importaciones:monitor 
                            {--tipo= : Filtrar por tipo (instituciones|docentes)}
                            {--estado= : Filtrar por estado (pending|processing|completed|failed)}
                            {--watch : Modo watch (actualiza cada 2 segundos)}';

    protected $description = 'Monitorea el estado de las importaciones';

    public function handle()
    {
        $tipo = $this->option('tipo');
        $estado = $this->option('estado');
        $watch = $this->option('watch');

        if ($watch) {
            $this->info('Modo watch activado. Presiona Ctrl+C para salir.');
            $this->newLine();
            
            while (true) {
                $this->mostrarEstadisticas($tipo, $estado);
                sleep(2);
                
                // Limpiar pantalla para refrescar
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    system('cls');
                } else {
                    system('clear');
                }
            }
        } else {
            $this->mostrarEstadisticas($tipo, $estado);
        }

        return 0;
    }

    protected function mostrarEstadisticas(?string $tipo, ?string $estado)
    {
        $query = ImportacionLog::query()->orderBy('created_at', 'desc');

        if ($tipo) {
            $query->where('tipo', $tipo);
        }

        if ($estado) {
            $query->where('estado', $estado);
        }

        $importaciones = $query->limit(10)->get();

        if ($importaciones->isEmpty()) {
            $this->warn('No se encontraron importaciones.');
            return;
        }

        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('               MONITOR DE IMPORTACIONES');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->newLine();

        // Estadísticas generales
        $enProgreso = ImportacionLog::enProgreso()->count();
        $completadas = ImportacionLog::completadas()->count();
        $fallidas = ImportacionLog::fallidas()->count();

        $this->table(
            ['Estado', 'Cantidad'],
            [
                ['En Progreso', $enProgreso],
                ['Completadas', $completadas],
                ['Fallidas', $fallidas],
            ]
        );

        $this->newLine();
        $this->info('Últimas 10 importaciones:');
        $this->newLine();

        // Tabla de importaciones
        $headers = ['ID', 'Tipo', 'Estado', 'Progreso', 'Éxito', 'Errores', 'Iniciado'];
        $rows = [];

        foreach ($importaciones as $importacion) {
            $estadoColor = $this->getEstadoColor($importacion->estado);
            $progreso = $importacion->total > 0 
                ? sprintf('%d/%d (%d%%)', $importacion->procesados, $importacion->total, $importacion->porcentaje)
                : 'N/A';

            $rows[] = [
                $importacion->id,
                ucfirst($importacion->tipo),
                $estadoColor,
                $progreso,
                $importacion->exitosos,
                $importacion->errores_count,
                $importacion->iniciado_en?->diffForHumans() ?? 'Pendiente',
            ];
        }

        $this->table($headers, $rows);

        // Detalles de importaciones en progreso
        $enProcesoDetalle = ImportacionLog::enProgreso()->get();
        
        if ($enProcesoDetalle->isNotEmpty()) {
            $this->newLine();
            $this->info('Detalles de importaciones en progreso:');
            $this->newLine();

            foreach ($enProcesoDetalle as $imp) {
                $this->line(sprintf(
                    '<fg=yellow>[ID: %d]</> %s | Estado: %s | Progreso: %d%% | Procesados: %d/%d | Tiempo: %s',
                    $imp->id,
                    ucfirst($imp->tipo),
                    $imp->estado,
                    $imp->porcentaje,
                    $imp->procesados,
                    $imp->total,
                    $imp->duracion_formateada ?? 'calculando...'
                ));
            }
        }

        $this->newLine();
        $this->info('Actualizado: ' . now()->format('Y-m-d H:i:s'));
    }

    protected function getEstadoColor(string $estado): string
    {
        return match($estado) {
            'pending' => '<fg=blue>Pendiente</>',
            'processing' => '<fg=yellow>Procesando</>',
            'completed' => '<fg=green>Completado</>',
            'failed' => '<fg=red>Fallido</>',
            default => $estado,
        };
    }
}