<?php

namespace App\Console\Commands;

use App\Models\Institucion;
use App\Models\ImportacionLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupBadInstituciones extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'instituciones:cleanup-bad
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--all : Delete all institutions instead of just bad ones}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up institutions with incorrect data (numeric names)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('=== Institution Cleanup Tool ===');
        $this->newLine();

        // Check current state
        $total = Institucion::count();
        $bad = Institucion::whereRaw('nombre REGEXP \'^[0-9]+$\'')->count();

        $this->info("Current state:");
        $this->line("  Total institutions: {$total}");
        $this->line("  Institutions with numeric names: {$bad}");
        $this->newLine();

        if ($bad === 0 && !$this->option('all')) {
            $this->info('No institutions with numeric names found. Nothing to clean up.');
            return 0;
        }

        // Show examples
        $this->info('Examples of institutions to be deleted:');
        $examples = $this->option('all')
            ? Institucion::take(10)->get(['id', 'codigo_modular_ie', 'nombre'])
            : Institucion::whereRaw('nombre REGEXP \'^[0-9]+$\'')->take(10)->get(['id', 'codigo_modular_ie', 'nombre']);

        $this->table(
            ['ID', 'Código Modular', 'Nombre'],
            $examples->map(fn($i) => [$i->id, $i->codigo_modular_ie, $i->nombre])
        );
        $this->newLine();

        // Determine what to delete
        $toDelete = $this->option('all') ? $total : $bad;
        $deleteType = $this->option('all') ? 'ALL institutions' : 'institutions with numeric names';

        if ($this->option('dry-run')) {
            $this->warn("DRY RUN: Would delete {$toDelete} {$deleteType}");
            return 0;
        }

        // Confirm deletion
        if (!$this->confirm("Are you sure you want to delete {$toDelete} {$deleteType}?", false)) {
            $this->info('Cleanup cancelled.');
            return 0;
        }

        // Perform deletion
        $this->info('Deleting institutions...');

        DB::beginTransaction();
        try {
            $deleted = $this->option('all')
                ? Institucion::query()->delete()
                : Institucion::whereRaw('nombre REGEXP \'^[0-9]+$\'')->delete();

            DB::commit();

            $this->info("✓ Successfully deleted {$deleted} institutions");

            // Show remaining count
            $remaining = Institucion::count();
            $this->line("  Remaining institutions: {$remaining}");

            // Ask about import logs
            if ($this->confirm('Do you also want to delete related import logs?', false)) {
                $logsDeleted = ImportacionLog::where('tipo', 'instituciones')
                    ->where('estado', 'completed')
                    ->delete();
                $this->info("✓ Deleted {$logsDeleted} import logs");
            }

            $this->newLine();
            $this->info('Cleanup completed successfully!');
            $this->line('You can now re-import institutions with the correct template.');

            return 0;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Error during cleanup: ' . $e->getMessage());
            return 1;
        }
    }
}
