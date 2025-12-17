<?php

namespace App\Console\Commands;

use App\Models\Institucion;
use App\Models\ImportacionLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupBadInstituciones extends Command
{
    protected $signature = 'instituciones:cleanup-bad
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--all : Delete all institutions instead of just bad ones}
                            {--keep-logs : Keep import logs (default: ask)}';

    protected $description = 'Clean up institutions with incorrect data (numeric names, invalid codes)';

    public function handle()
    {
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('           INSTITUTION CLEANUP TOOL');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->newLine();

        // ✅ Estado actual
        $total = Institucion::count();
        $numericNames = Institucion::whereRaw("nombre REGEXP '^[0-9]+$'")->count();
        $emptyNames = Institucion::whereNull('nombre')->orWhere('nombre', '')->count();
        $invalidCodes = Institucion::whereRaw("LENGTH(codigo_modular_ie) < 6")->count();

        $this->info('📊 Current database state:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total institutions', $total],
                ['Institutions with numeric names', $numericNames],
                ['Institutions with empty names', $emptyNames],
                ['Institutions with invalid codes (<6 chars)', $invalidCodes],
            ]
        );
        $this->newLine();

        // ✅ Determinar qué eliminar
        $toDelete = $this->option('all') ? $total : ($numericNames + $emptyNames);
        
        if ($toDelete === 0 && !$this->option('all')) {
            $this->info('✅ No bad institutions found. Database is clean!');
            return 0;
        }

        // ✅ Mostrar ejemplos
        $this->info('📋 Examples of institutions to be deleted:');
        
        $examples = $this->option('all')
            ? Institucion::take(10)->get(['id', 'codigo_modular_ie', 'nombre', 'distrito'])
            : Institucion::where(function ($q) {
                $q->whereRaw("nombre REGEXP '^[0-9]+$'")
                  ->orWhereNull('nombre')
                  ->orWhere('nombre', '');
            })->take(10)->get(['id', 'codigo_modular_ie', 'nombre', 'distrito']);

        if ($examples->isEmpty()) {
            $this->warn('No examples to show.');
        } else {
            $this->table(
                ['ID', 'Código Modular', 'Nombre', 'Distrito'],
                $examples->map(fn($i) => [
                    $i->id,
                    $i->codigo_modular_ie,
                    $i->nombre ?: '<empty>',
                    $i->distrito ?: '<empty>',
                ])
            );
        }
        $this->newLine();

        // ✅ Determinar tipo de eliminación
        $deleteType = $this->option('all') 
            ? 'ALL institutions' 
            : 'institutions with bad data (numeric/empty names)';

        // ✅ Dry run
        if ($this->option('dry-run')) {
            $this->warn("🔍 DRY RUN: Would delete {$toDelete} {$deleteType}");
            $this->line('   No data was actually deleted.');
            $this->newLine();
            $this->line('💡 To execute the cleanup, run without --dry-run flag.');
            return 0;
        }

        // ✅ Confirmación
        if (!$this->confirm("⚠️  Are you sure you want to delete {$toDelete} {$deleteType}?", false)) {
            $this->info('✅ Cleanup cancelled. No data was deleted.');
            return 0;
        }

        // ✅ Realizar eliminación
        $this->newLine();
        $this->info('🧹 Deleting institutions...');

        DB::beginTransaction();
        
        try {
            $deleted = $this->option('all')
                ? Institucion::query()->delete()
                : Institucion::where(function ($q) {
                    $q->whereRaw("nombre REGEXP '^[0-9]+$'")
                      ->orWhereNull('nombre')
                      ->orWhere('nombre', '');
                })->delete();

            DB::commit();

            $this->newLine();
            $this->info("✅ Successfully deleted {$deleted} institutions");

            // ✅ Mostrar instituciones restantes
            $remaining = Institucion::count();
            $this->line("   Remaining institutions: {$remaining}");

            // ✅ Preguntar por import logs
            $deleteLogsDefault = !$this->option('keep-logs');
            
            if ($this->confirm('🗑️  Do you also want to delete related import logs?', $deleteLogsDefault)) {
                $logsDeleted = ImportacionLog::where('tipo', ImportacionLog::TIPO_INSTITUCIONES)
                    ->where('estado', ImportacionLog::ESTADO_COMPLETED)
                    ->delete();
                    
                $this->info("✅ Deleted {$logsDeleted} import logs");
            } else {
                $this->line('   Import logs preserved.');
            }

            // ✅ Resumen final
            $this->newLine();
            $this->info('═══════════════════════════════════════════════════════════════');
            $this->info('✅ Cleanup completed successfully!');
            $this->info('═══════════════════════════════════════════════════════════════');
            $this->newLine();
            $this->line('💡 Next steps:');
            $this->line('   1. Review your Excel template');
            $this->line('   2. Ensure column mapping is correct');
            $this->line('   3. Re-import institutions: php artisan instituciones:import');

            return 0;

        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->newLine();
            $this->error('❌ Error during cleanup: ' . $e->getMessage());
            $this->error('   No data was deleted due to the error.');
            
            if ($this->option('verbose')) {
                $this->newLine();
                $this->line('Stack trace:');
                $this->line($e->getTraceAsString());
            }
            
            return 1;
        }
    }
}