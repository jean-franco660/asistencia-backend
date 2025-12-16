<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupTestData extends Command
{
    protected $signature = 'dev:cleanup-test-data
                            {--force : Skip confirmation}';

    protected $description = 'Clean up all test data (institutions, docentes, import logs)';

    public function handle()
    {
        if (!app()->environment('local', 'development')) {
            $this->error('This command can only be run in local/development environment!');
            return 1;
        }

        $this->warn('⚠️  This will delete ALL test data:');
        $this->line('  - All institutions');
        $this->line('  - All docentes (usuarios_app)');
        $this->line('  - All docente-institucion relations');
        $this->line('  - All import logs');
        $this->line('  - All asistencias');
        $this->newLine();

        if (!$this->option('force') && !$this->confirm('Are you sure?', false)) {
            $this->info('Cleanup cancelled.');
            return 0;
        }

        $this->info('Starting cleanup...');

        $tables = [
            'asistencias' => 'asistencias',
            'docente_institucion' => 'docente-institucion relations',
            'usuarios_app' => 'docentes',
            'supervisor_institucion' => 'supervisor-institucion relations',
            'horarios_institucion' => 'horarios',
            'instituciones' => 'instituciones',
            'importacion_logs' => 'import logs',
        ];

        $totalDeleted = 0;

        foreach ($tables as $table => $description) {
            try {
                $deleted = DB::table($table)->delete();
                $this->line("✓ Deleted {$deleted} {$description}");
                $totalDeleted += $deleted;
            } catch (\Exception $e) {
                $this->warn("⚠ Skipped {$table}: " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("✅ Cleanup completed! Total records deleted: {$totalDeleted}");
        $this->line('You can now import fresh data.');

        return 0;
    }
}
