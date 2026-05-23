<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CreateDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:create';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create the configured database if it does not exist.';

    public function handle()
    {
        $database = env('DB_DATABASE') ?: env('MYSQLDATABASE');
        $host = env('DB_HOST') ?: env('MYSQLHOST', '127.0.0.1');
        $port = env('DB_PORT') ?: env('MYSQLPORT', '3306');
        $username = env('DB_USERNAME') ?: env('MYSQLUSER', 'root');
        $password = env('DB_PASSWORD') ?: env('MYSQLPASSWORD', '');

        if (empty($database)) {
            $this->error('Database name is not configured (DB_DATABASE or MYSQLDATABASE).');
            return 1;
        }

        try {
            $dsn = sprintf('mysql:host=%s;port=%s', $host, $port);
            $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];
            $pdo = new \PDO($dsn, $username, $password, $options);

            $charset = env('DB_CHARSET', 'utf8mb4');
            $collation = env('DB_COLLATION', 'utf8mb4_unicode_ci');

            $sql = sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s;', $database, $charset, $collation);
            $pdo->exec($sql);

            $this->info("Database '{$database}' exists or was created successfully.");
        } catch (\PDOException $e) {
            $this->error('Failed to ensure database: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
