<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanupTestData extends Command
{
    protected $signature = 'dev:cleanup-test-data
                            {--force : Skip confirmation}
                            {--tables=* : Specify specific tables to clean (optional)}';

    protected $description = 'Clean up all test data (institutions, usuarios_app, import logs)';

    public function handle()
    {
        // ✅ Protección: Solo en local/development
        if (!app()->environment('local', 'development')) {
            $this->error('❌ This command can only be run in local/development environment!');
            $this->error('   Current environment: ' . app()->environment());
            return 1;
        }

        // ✅ Advertencia clara
        $this->warn('⚠️  WARNING: This will delete ALL test data!');
        $this->newLine();

        $this->line('📋 Tables to be cleaned:');
        $this->line('  - asistencias (attendance records)');
        $this->line('  - justificaciones (justifications)');
        $this->line('  - usuario_app_institucion (user-institution relations)');
        $this->line('  - usuarios_app (app users)');
        $this->line('  - supervisor_institucion (supervisor-institution relations)');
        $this->line('  - horarios_institucion (schedules)');
        $this->line('  - instituciones (institutions)');
        $this->line('  - importaciones_log (import logs)');
        $this->newLine();

        // ✅ Confirmación obligatoria sin --force
        if (!$this->option('force')) {
            if (!$this->confirm('⚠️  Are you ABSOLUTELY sure you want to delete ALL data?', false)) {
                $this->info('✅ Cleanup cancelled. No data was deleted.');
                return 0;
            }

            // ✅ Segunda confirmación para seguridad extra
            if (!$this->confirm('⚠️  This action cannot be undone. Continue?', false)) {
                $this->info('✅ Cleanup cancelled. No data was deleted.');
                return 0;
            }
        }

        $this->newLine();
        $this->info('🧹 Starting cleanup...');
        $this->newLine();

        // ✅ Desactivar FK checks temporalmente
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // ✅ Orden correcto de tablas (de dependientes a independientes)
        $tables = [
            'asistencias' => 'attendance records',
            'justificaciones' => 'justifications',
            'usuario_app_institucion' => 'user-institution relations',
            'usuarios_app' => 'app users',
            'supervisor_institucion' => 'supervisor-institution relations',
            'horarios_institucion' => 'institution schedules',
            'instituciones' => 'institutions',
            'importaciones_log' => 'import logs',
        ];

        $totalDeleted = 0;
        $errors = [];

        // ✅ Usar progress bar para feedback visual
        $bar = $this->output->createProgressBar(count($tables));
        $bar->start();

        foreach ($tables as $table => $description) {
            try {
                // ✅ Verificar que la tabla existe
                if (!Schema::hasTable($table)) {
                    $this->newLine();
                    $this->warn("⚠️  Table '{$table}' does not exist, skipping...");
                    $bar->advance();
                    continue;
                }

                $deleted = DB::table($table)->delete();

                $this->newLine();
                $this->line("  ✓ Deleted {$deleted} {$description} from '{$table}'");

                $totalDeleted += $deleted;

            } catch (\Exception $e) {
                $this->newLine();
                $this->warn("  ⚠️  Error cleaning '{$table}': " . $e->getMessage());
                $errors[] = [
                    'table' => $table,
                    'error' => $e->getMessage(),
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // ✅ Reactivar FK checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // ✅ Resumen final
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info("✅ Cleanup completed!");
        $this->info("   Total records deleted: {$totalDeleted}");

        if (!empty($errors)) {
            $this->newLine();
            $this->warn("⚠️  " . count($errors) . " table(s) had errors:");
            foreach ($errors as $error) {
                $this->line("   - {$error['table']}: {$error['error']}");
            }
        }

        $this->info('═══════════════════════════════════════════════════════════════');
        $this->newLine();
        $this->line('💡 You can now import fresh data.');
        $this->line('   Run: php artisan importaciones:monitor --watch');

        return 0;
    }
}