<?php

namespace App\Console\Commands;

use App\Models\ImportacionLog;
use Illuminate\Console\Command;

class MonitorImportacionesCommand extends Command
{
    protected $signature = 'importaciones:monitor 
                            {--tipo= : Filtrar por tipo (instituciones|usuarios_app|asignaciones|asistencias)}
                            {--estado= : Filtrar por estado (pending|processing|completed|failed)}
                            {--watch : Modo watch (actualiza cada 2 segundos)}';

    protected $description = 'Monitorea el estado de las importaciones en tiempo real';

    public function handle()
    {
        $tipo = $this->option('tipo');
        $estado = $this->option('estado');
        $watch = $this->option('watch');

        //  NUEVO: Validar tipo
        if ($tipo && !in_array($tipo, ImportacionLog::getTiposDisponibles())) {
            $this->error('Tipo inválido. Tipos permitidos: ' . implode(', ', ImportacionLog::getTiposDisponibles()));
            return 1;
        }

        //  NUEVO: Validar estado
        if ($estado && !in_array($estado, ImportacionLog::getEstadosDisponibles())) {
            $this->error('Estado inválido. Estados permitidos: ' . implode(', ', ImportacionLog::getEstadosDisponibles()));
            return 1;
        }

        if ($watch) {
            $this->info(' Modo watch activado. Presiona Ctrl+C para salir.');
            $this->newLine();
            
            while (true) {
                // Limpiar pantalla
                $this->clearScreen();
                
                $this->mostrarEstadisticas($tipo, $estado);
                
                sleep(2);
            }
        } else {
            $this->mostrarEstadisticas($tipo, $estado);
        }

        return 0;
    }

    protected function mostrarEstadisticas(?string $tipo, ?string $estado): void
    {
        $query = ImportacionLog::query()->orderBy('created_at', 'desc');

        if ($tipo) {
            $query->where('tipo', $tipo);
        }

        if ($estado) {
            $query->where('estado', $estado);
        }

        $importaciones = $query->limit(10)->get();

        // Header
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('               MONITOR DE IMPORTACIONES');
        $this->info('═══════════════════════════════════════════════════════════════');
        
        //  NUEVO: Mostrar filtros activos
        if ($tipo || $estado) {
            $filtros = [];
            if ($tipo) $filtros[] = "Tipo: {$tipo}";
            if ($estado) $filtros[] = "Estado: {$estado}";
            $this->line('Filtros activos: ' . implode(' | ', $filtros));
        }
        
        $this->newLine();

        // Estadísticas generales
        $enProgreso = ImportacionLog::enProgreso()->count();
        $completadas = ImportacionLog::completadas()->count();
        $fallidas = ImportacionLog::fallidas()->count();

        $this->table(
            ['Estado', 'Cantidad'],
            [
                ['⏳ En Progreso', $enProgreso],
                [' Completadas', $completadas],
                [' Fallidas', $fallidas],
            ]
        );

        $this->newLine();

        if ($importaciones->isEmpty()) {
            $this->warn('No se encontraron importaciones con los filtros aplicados.');
            return;
        }

        $this->info('Últimas 10 importaciones:');
        $this->newLine();

        // Tabla de importaciones
        $headers = ['ID', 'Tipo', 'Estado', 'Progreso', 'Éxito', 'Errores', 'Duración', 'Iniciado'];
        $rows = [];

        foreach ($importaciones as $importacion) {
            $estadoColor = $this->getEstadoColor($importacion->estado);
            
            $progreso = $importacion->total > 0 
                ? sprintf('%d/%d (%d%%)', $importacion->procesados, $importacion->total, $importacion->porcentaje)
                : 'N/A';

            $rows[] = [
                $importacion->id,
                $importacion->tipo_formateado,  //  Usar accessor
                $estadoColor,
                $progreso,
                $importacion->exitosos,
                $importacion->errores_count,
                $importacion->duracion_formateada ?? 'N/A',  //  Usar accessor
                $importacion->iniciado_en?->diffForHumans() ?? 'Pendiente',
            ];
        }

        $this->table($headers, $rows);

        // Detalles de importaciones en progreso
        $enProcesoDetalle = ImportacionLog::enProgreso()->get();
        
        if ($enProcesoDetalle->isNotEmpty()) {
            $this->newLine();
            $this->info(' Detalles de importaciones en progreso:');
            $this->newLine();

            foreach ($enProcesoDetalle as $imp) {
                $this->line(sprintf(
                    '<fg=yellow>[ID: %d]</> %s | Estado: %s | Progreso: %d%% | Procesados: %d/%d | Tiempo: %s | Tasa éxito: %.2f%%',
                    $imp->id,
                    $imp->tipo_formateado,
                    $imp->estado_formateado,
                    $imp->porcentaje,
                    $imp->procesados,
                    $imp->total,
                    $imp->duracion_formateada ?? 'calculando...',
                    $imp->tasa_exito  //  Usar accessor
                ));
            }
        }

        $this->newLine();
        $this->info(' Actualizado: ' . now()->format('Y-m-d H:i:s'));
    }

    protected function getEstadoColor(string $estado): string
    {
        return match($estado) {
            ImportacionLog::ESTADO_PENDING => '<fg=blue>⏳ Pendiente</>',
            ImportacionLog::ESTADO_PROCESSING => '<fg=yellow>️  Procesando</>',
            ImportacionLog::ESTADO_COMPLETED => '<fg=green> Completado</>',
            ImportacionLog::ESTADO_FAILED => '<fg=red> Fallido</>',
            default => $estado,
        };
    }

    /**
     * Limpia la pantalla según el sistema operativo
     */
    protected function clearScreen(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            system('cls');
        } else {
            system('clear');
        }
    }
}