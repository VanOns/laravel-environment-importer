<?php

namespace VanOns\LaravelEnvironmentImporter\Commands;

use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Exceptions\CannotSetParameter;
use Symfony\Component\Process\Process;
use VanOns\LaravelEnvironmentImporter\Exceptions\ImportEnvironmentException;
use VanOns\LaravelEnvironmentImporter\Notifications\ImportFailed;
use VanOns\LaravelEnvironmentImporter\Notifications\ImportSucceeded;
use VanOns\LaravelEnvironmentImporter\Processors\DataProcessor;
use VanOns\LaravelEnvironmentImporter\Support\AsyncProcess;
use function Laravel\Prompts\select;

class ImportEnvironmentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'environment:import
                            {--target= : The target environment to import}
                            {--safe : Skip all prompts and keep all files after import}
                            {--clean : Skip all prompts and clean up all files after import}
                            {--skip-db : Skip importing the database}
                            {--skip-files : Skip importing the files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import the target environment and set it up locally';

    protected bool $safe = false;

    protected bool $clean = false;

    protected bool $skipDb = false;

    protected bool $skipFiles = false;

    protected Carbon $startedAt;

    protected string $target;

    protected array $config = [];

    protected array $environmentConfig = [];

    protected bool $backupDestination = false;

    protected bool $cleanDestination = false;

    protected string $backupPath;

    protected ?Process $dbTunnelProcess = null;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $this->setup();
            $this->importDatabase();
            $this->importFiles();
            $this->flushCache();
            $this->finish();
        } catch (Exception $e) {
            $this->error($e->getMessage());
            $this->sendFailureNotification($e);
            $this->afterRemoteDatabaseConnection();

            return static::FAILURE;
        }

        return static::SUCCESS;
    }

    /**
     * Prepare the import process.
     *
     * @throws ImportEnvironmentException
     */
    protected function setup(): void
    {
        if ($this->safe = $this->option('safe')) {
            $this->info("\n--- Running in safe mode (keeping DB dump and files after import) ---");
        } elseif ($this->clean = $this->option('clean')) {
            $this->warn("\n--- Running in clean mode (removing DB dump and files after import) ---");
        }

        $this->skipDb = $this->option('skip-db');
        $this->skipFiles = $this->option('skip-files');

        $this->config = config('environment-importer', []);
        $this->startedAt = now();

        $environments = $this->getEnvironments();
        if (empty($environments)) {
            throw new ImportEnvironmentException('No environments found to import from');
        }

        $this->target = $this->option('target') ?? select('Select the target environment', $environments);
        if (!array_key_exists($this->target, $this->getConfigValue('environments', []))) {
            throw new ImportEnvironmentException("The \"{$this->target}\" environment does not exist");
        }

        $this->environmentConfig = $this->getConfigValue("environments.{$this->target}", []);
        if (empty($this->environmentConfig)) {
            throw new ImportEnvironmentException("No configuration found for the \"{$this->target}\" environment");
        }

        $this->line("\nImporting the \"{$this->target}\" environment...\n");

        // Determine if the destination should be backed up.
        $this->backupDestination = match (true) {
            $this->safe => true,
            $this->clean => false,
            default => select('Do you want to back up the existing destination?', ['Yes', 'No']) === 'Yes',
        };

        if ($this->backupDestination) {
            // Determine if the destination should be cleaned.
            $this->cleanDestination = match (true) {
                $this->safe => false,
                $this->clean => true,
                default => select('Do you want to clean the existing destination?', ['Yes', 'No']) === 'Yes',
            };
        }

        // Create the cache folder.
        $this->backupPath = base_path($this->getConfigValue('backup_path', '.import') . '/' . $this->startedAt->format('Y-m-d_H-i-s'));
        $this->ensureDirectoryExists($this->backupPath);
    }

    /**
     * Get the environments that can be imported from.
     */
    protected function getEnvironments(): array
    {
        // These keys must be present in each environment.
        $keys = [
            'ssh_host',
            'ssh_username',
            'ssh_base_path',
            'db_host',
            'db_name',
            'db_username',
            'db_password',
            'db_port',
        ];

        // At least one of the keys in each array must be present in each environment.
        $eitherKeys = [
            ['ssh_key', 'ssh_password'],
        ];

        $environments = [];

        foreach ($this->getConfigValue('environments', []) as $environment => $config) {
            $isValid = collect($keys)->every(fn (string $key) => !empty($config[$key]))
                && collect($eitherKeys)->every(fn (array $keys) => collect($keys)->some(fn (string $key) => !empty($config[$key])));

            if ($isValid) {
                $environments[] = $environment;
            }
        }

        return $environments;
    }

    /**
     * Import the database.
     *
     * @throws CannotSetParameter
     * @throws ImportEnvironmentException
     */
    protected function importDatabase(): void
    {
        if ($this->skipDb) {
            $this->warn('[DB] Skipping database import.');
            return;
        }

        $this->line('[DB] Importing database...');

        $dumpPath = "{$this->backupPath}/db";
        $this->ensureDirectoryExists($dumpPath);

        $dumpFile = "{$dumpPath}/{$this->target}.sql";

        $this->createLocalBackupDump($dumpPath);
        $this->createDumpForImport($dumpPath, $dumpFile);
        $this->wipeLocalDatabase();
        $this->importDatabaseDump($dumpFile);
        $this->processDatabaseData();
        $this->afterDatabaseImport($dumpPath);
        $this->runDatabaseMigrations();

        $this->info('[DB] Database imported.');
    }

    /**
     * Dump the local database.
     */
    protected function createLocalBackupDump(string $dumpPath): void
    {
        $this->line('[DB] Backing up local database...');

        $dumpFile = "{$dumpPath}/local.sql";

        $this->getDatabaseDumpClient(true)
            ->dumpToFile($dumpFile);

        $this->info("[DB] Backed up local database to \"{$dumpFile}\".");
    }

    /**
     * Dump the remote database.
     *
     * @throws CannotSetParameter
     * @throws ImportEnvironmentException
     */
    protected function createDumpForImport(string $dumpPath, string $dumpFile): void
    {
        $this->line('[DB] Dumping target database...');

        $exclude = [];
        $files = [];

        $persistTables = $this->getConfigValue('persist_tables', []);
        if (!empty($persistTables)) {
            $this->line('[DB] Processing persist tables...');

            foreach ($persistTables as $table) {
                $tableDumpFile = "{$dumpPath}/local_{$table}.sql";
                $files[] = $tableDumpFile;

                $this->getDatabaseDumpClient(true)
                    ->includeTables([$table])
                    ->dumpToFile($tableDumpFile);

                $exclude[] = $table;
            }
        }

        $this->beforeRemoteDatabaseConnection();

        $emptyTables = $this->getConfigValue('empty_tables', []);

        // Fallback to old config key for backwards compatibility
        if (empty($emptyTables)) {
            $emptyTables = $this->getConfigValue('sensitive_tables', []);
        }

        if (!empty($emptyTables)) {
            $this->line('[DB] Processing empty tables...');

            // Dump sensitive tables separately so we only get their CREATE statements, but not their data.
            foreach ($emptyTables as $table) {
                $tableDumpFile = "{$dumpPath}/{$this->target}_{$table}.sql";
                $files[] = $tableDumpFile;

                $this->getDatabaseDumpClient()
                    ->doNotDumpData()
                    ->includeTables([$table])
                    ->dumpToFile($tableDumpFile);

                $exclude[] = $table;
            }
        }

        $this->line('[DB] Processing other tables...');

        $baseDumpFile = "{$dumpPath}/{$this->target}_base.sql";
        $this->getDatabaseDumpClient()
            ->excludeTables($exclude)
            ->dumpToFile($baseDumpFile);

        $this->afterRemoteDatabaseConnection();
        $this->buildDumpFile($dumpFile, $baseDumpFile, $files);

        $this->line('[DB] Deleting intermediate dump files...');

        // Clean up the temporary dump files, we just want to keep the final file.
        File::delete([
            $baseDumpFile,
            ...$files,
        ]);

        $this->info("[DB] Dumped target database to \"{$dumpFile}\".");
    }

    /**
     * Get the database dump client.
     */
    protected function getDatabaseDumpClient(bool $local = false): MySql
    {
        $port = match (true) {
            $local => DB::getConfig('port'),
            $this->dbUseSsh() => $this->dbSshTunnelPort(),
            default => $this->dbPort(),
        };

        /** @phpstan-ignore-next-line */
        return MySql::create()
            ->setHost($local ? DB::getConfig('host') : $this->getEnvironmentConfigValue('db_host'))
            ->setDbName($local ? DB::getConfig('database') : $this->getEnvironmentConfigValue('db_name'))
            ->setUserName($local ? DB::getConfig('username') : $this->getEnvironmentConfigValue('db_username'))
            ->setPassword($local ? DB::getConfig('password') : $this->getEnvironmentConfigValue('db_password'))
            ->setPort($port)
            ->setDumpBinaryPath($this->getConfigValue('db_dump_binary_path', '/usr/bin'));
    }

    /**
     * Set up before connecting to the remote database.
     *
     * @throws ImportEnvironmentException
     */
    protected function beforeRemoteDatabaseConnection(): void
    {
        if (!$this->dbUseSsh()) {
            return;
        }

        if (!$this->dbTunnelProcess) {
            $this->dbTunnelProcess = new AsyncProcess([
                'ssh',
                '-N',
                '-L',
                "{$this->dbSshTunnelPort()}:127.0.0.1:{$this->dbPort()}",
                "{$this->getEnvironmentConfigValue('ssh_username')}@{$this->getEnvironmentConfigValue('ssh_host')}",
                $this->sshAuth(),
            ]);
        }

        if (!$this->dbTunnelProcess->isRunning()) {
            $this->line('[DB] Starting SSH tunnel...');

            $this->dbTunnelProcess->start();

            $this->line('[DB] Waiting for SSH tunnel to start...');

            // Wait for the tunnel to start.
            $tries = 10;
            do {
                if ($tries <= 0) {
                    throw new ImportEnvironmentException('Failed to start SSH tunnel');
                }

                $tries--;
                sleep(2);
            } while (!$this->dbTunnelProcess->isRunning());

            $this->info('[DB] SSH tunnel started.');
        }
    }

    /**
     * Clean up after connecting to the remote database.
     */
    protected function afterRemoteDatabaseConnection(): void
    {
        if (!$this->dbTunnelProcess?->isRunning() || !$this->dbUseSsh()) {
            return;
        }

        $this->line('[DB] Stopping SSH tunnel...');

        $this->dbTunnelProcess->stop();
    }

    /**
     * Build the final dump file in chunks to prevent memory issues.
     */
    protected function buildDumpFile(string $finalDumpFile, string $baseDumpFile, array $files): void
    {
        $this->line('[DB] Building dump file...');

        $baseDumpHandle = fopen($baseDumpFile, 'rb');
        $finalDumpHandle = fopen($finalDumpFile, 'wb');

        // Write the base dump file content to the final dump file.
        while (!feof($baseDumpHandle)) {
            fwrite($finalDumpHandle, fread($baseDumpHandle, 8192));
        }

        fclose($baseDumpHandle);

        // Append the content of each file to the final dump file.
        foreach ($files as $file) {
            $fileHandle = fopen($file, 'rb');
            while (!feof($fileHandle)) {
                fwrite($finalDumpHandle, fread($fileHandle, 8192));
            }

            fclose($fileHandle);
        }

        fclose($finalDumpHandle);
    }

    /**
     * Wipe the local database.
     */
    protected function wipeLocalDatabase(): void
    {
        $this->line('[DB] Wiping local database...');

        Artisan::call('db:wipe', ['--force' => true], $this->output);

        $this->info('[DB] Wiped local database.');
    }

    /**
     * Import the database dump.
     *
     * @throws ImportEnvironmentException
     */
    protected function importDatabaseDump(string $dumpFile): void
    {
        $this->line('[DB] Importing database dump...');

        match ($connection = DB::getDefaultConnection()) {
            'mysql' => $this->importMysqlDump($dumpFile),
            'sqlite' => $this->importSqliteDump($dumpFile),
            'pgsql' => $this->importPgsqlDump($dumpFile),
            default => throw new ImportEnvironmentException('Unsupported database connection type: ' . $connection),
        };

        $this->info("[DB] Imported database dump from \"{$dumpFile}\".");
    }

    /**
     * Import the database dump using the MySQL client.
     *
     * @throws ImportEnvironmentException
     */
    protected function importMysqlDump(string $dumpFile): void
    {
        $binary = rtrim($this->getConfigValue('db_import_binary_path', '/usr/bin'), '/') . '/mysql';
        $command = sprintf(
            '%s --host=%s --port=%s --user=%s %s < %s',
            escapeshellcmd($binary),
            DB::getConfig('host'),
            DB::getConfig('port'),
            DB::getConfig('username'),
            DB::getConfig('database'),
            escapeshellarg($dumpFile)
        );

        $process = Process::fromShellCommandline($command, env: [
            'MYSQL_PWD' => DB::getConfig('password'),
        ]);

        $process->run();

        if (!$process->isSuccessful()) {
            throw new ImportEnvironmentException('Failed to import MySQL database dump: ' . $process->getErrorOutput());
        }
    }

    /**
     * @throws ImportEnvironmentException
     */
    protected function importSqliteDump(string $dumpFile): void
    {
        $destination = DB::getConfig('database');

        if (!File::copy($dumpFile, $destination)) {
            throw new ImportEnvironmentException("Failed to import SQLite database dump from \"{$dumpFile}\" to \"{$destination}\"");
        }
    }

    /**
     * @throws ImportEnvironmentException
     */
    protected function importPgsqlDump(string $dumpFile): void
    {
        $binary = rtrim($this->getConfigValue('db_import_binary_path', '/usr/bin'), '/') . '/psql';
        $process = new Process([
            $binary,
            '--host=' . DB::getConfig('host'),
            '--port=' . DB::getConfig('port'),
            '--username=' . DB::getConfig('username'),
            '--dbname=' . DB::getConfig('database'),
            '-f', $dumpFile,
        ], null, ['PGPASSWORD' => DB::getConfig('password')]);

        $process->run();

        if (!$process->isSuccessful()) {
            throw new ImportEnvironmentException('Failed to import PostgreSQL database dump: ' . $process->getErrorOutput());
        }
    }

    /**
     * Process the data in the database.
     *
     * @throws ImportEnvironmentException
     */
    protected function processDatabaseData(): void
    {
        $processors = $this->getConfigValue('data_processors', []);

        if (empty($processors)) {
            $this->line('[DB] No data processors found, skipping data processing.');
            return;
        }

        $this->line('[DB] Processing data in database...');

        $tables = collect(Schema::getTables())->pluck('name')->toArray();

        foreach ($processors as $key => $value) {
            if (is_int($key)) {
                $processorClass = $value;
                $options = [];
            } else {
                $processorClass = $key;
                $options = $value;
            }

            if (!is_a($processorClass, DataProcessor::class, true)) {
                throw new ImportEnvironmentException("The processor \"{$processorClass}\" must extend \"VanOns\LaravelEnvironmentImporter\Processors\DataProcessor\"");
            }

            foreach ($tables as $table) {
                $processor = new $processorClass($table, $options);

                if ($processor->applies()) {
                    $this->line("[DB] Processing data for table \"{$table}\" using \"{$processorClass}\"...");
                    $processor->process();
                }
            }
        }

        $this->info('[DB] Processed data in database.');
    }

    /**
     * Perform actions after the database import.
     */
    protected function afterDatabaseImport(string $dumpPath): void
    {
        // Determine if the database dump should be deleted.
        $clean = match (true) {
            $this->safe => false,
            $this->clean => true,
            default => select('Do you want to delete the database dumps?', ['Yes', 'No']) === 'Yes',
        };

        if (!$clean) {
            return;
        }

        $this->line('[DB] Deleting database dump...');

        File::deleteDirectory($dumpPath);

        $this->info('[DB] Deleted database dump.');
    }

    /**
     * Run the database migrations.
     */
    protected function runDatabaseMigrations(): void
    {
        $this->line('[DB] Running database migrations...');

        Artisan::call('migrate', ['--force' => true], $this->output);

        $this->info('[DB] Ran database migrations.');
    }

    /**
     * Import the files.
     *
     * @throws ImportEnvironmentException
     */
    protected function importFiles(): void
    {
        if ($this->skipFiles) {
            $this->warn('[Files] Skipping file import.');
            return;
        }

        $this->line('[Files] Importing files...');

        foreach ($this->getConfigValue('import_paths', []) as $key => $extra) {
            $this->importSingle($key, $extra);
        }

        $this->createFrameworkFolders();

        $this->info('[Files] Files imported.');
    }

    /**
     * Import a single file or directory.
     *
     * @throws ImportEnvironmentException
     */
    protected function importSingle(string|int $key, string|array $extra): void
    {
        $path = $extra;
        $excludes = [];

        if (!is_int($key)) {
            $path = $key;
            $excludes = $extra['excludes'] ?? [];
        }

        if (empty($path) || !is_string($path)) {
            throw new ImportEnvironmentException('No valid path defined for file import');
        }

        $this->line("[Files] Importing \"{$path}\"...");

        // Make sure the source ends with a /.
        $source = $this->getEnvironmentConfigValue('ssh_base_path') . "/{$path}" . (str_ends_with($path, '/') ? '' : '/');
        $destination = base_path($path);

        $this->backupDestination($destination, $path);

        $excludesArg = '';
        if (!empty($excludes)) {
            $excludesArg = '--exclude="' . implode('" --exclude="', $excludes) . '"';
        }

        $command = sprintf(
            'rsync -zaHLK --progress --stats -e "ssh %s" %s %s@%s:%s %s',
            $this->sshAuth(),
            $excludesArg,
            $this->getEnvironmentConfigValue('ssh_username'),
            $this->getEnvironmentConfigValue('ssh_host'),
            $source,
            $destination
        );

        $progressBar = $this->getOutput()->createProgressBar();
        $process = Process::fromShellCommandline($command)->setTimeout(null);
        $total = 0;
        $current = 0;
        $rsyncStart = now();

        $this->line('[Files] Starting import...');

        // Run the process and call specific functionality based on the output.
        $process->run(function (string $type, string $buffer) use ($progressBar, &$total, &$current) {
            // Set the progress bar total based on the total number of files.
            if (preg_match('/(\d+)\s+files to consider/', $buffer, $matches)) {
                $total = (int) $matches[1];
                $progressBar->start($total);
            }

            // Increment the progress bar for every file that reaches 100%.
            if (preg_match('/100%/', $buffer, $matches)) {
                $current++;
                $progressBar->setProgress($current);
            }

            // Finish the progress bar and display the total number of transferred files.
            if (preg_match('/Number of files transferred:\s+(\d+)/', $buffer, $matches)) {
                $transferred = (int) $matches[1];

                $progressBar->finish();
                $this->newLine(2);
                $this->info("[Files] {$transferred} file(s) transferred | " . ($total - $transferred) . ' file(s) skipped.');
            }
        });

        if (!$process->isSuccessful()) {
            throw new ImportEnvironmentException("rsync failed: {$process->getErrorOutput()}");
        }

        $rsyncEnd = now();

        $this->info("[Files] Imported \"{$path}\" in {$rsyncStart->diffInSeconds($rsyncEnd)} seconds.");
    }

    /**
     * Back up the destination.
     *
     * @throws ImportEnvironmentException
     */
    protected function backupDestination(string $destination, string $path): void
    {
        if (!$this->backupDestination) {
            return;
        }

        if (File::exists($destination)) {
            $this->line("[Files] Backing up \"{$destination}\"...");

            // Create the backup folder.
            $backupPath = "{$this->backupPath}/backup/{$path}";
            $this->ensureDirectoryExists($backupPath);

            if (!File::copyDirectory($destination, $backupPath)) {
                throw new ImportEnvironmentException("Failed to back up \"{$destination}\"");
            }

            if ($this->cleanDestination && !File::cleanDirectory($destination)) {
                throw new ImportEnvironmentException("Failed to clean \"{$destination}\"");
            }

            $this->info("[Files] Backed up \"{$destination}\".");

            return;
        }

        $this->ensureDirectoryExists($destination);
    }

    /**
     * Create the folders Laravel needs to function.
     */
    protected function createFrameworkFolders(): void
    {
        $this->line('[Files] Creating framework folders...');

        $requiredFolders = [
            'storage/app',
            'storage/logs',
            'storage/statamic',
            'storage/framework/cache',
            'storage/framework/logs',
            'storage/framework/sessions',
            'storage/framework/statamic',
            'storage/framework/testing',
            'storage/framework/views',
        ];

        foreach ($requiredFolders as $folder) {
            if (!File::exists(base_path($folder))) {
                File::makeDirectory(base_path($folder), recursive: true);
            }
        }

        $this->info('[Files] Framework folders created.');
    }

    /**
     * Flush the cache.
     */
    protected function flushCache(): void
    {
        $this->line('[Cache] Flushing cache...');

        Artisan::call('optimize:clear', outputBuffer: $this->output);

        $this->info('[Cache] Cache flushed.');
    }

    /**
     * Clean up and finish the import.
     */
    protected function finish(): void
    {
        if (File::isEmptyDirectory($this->backupPath)) {
            File::deleteDirectory($this->backupPath);
        }

        $this->sendSuccessNotification();

        if ($this->backupDestination && !$this->cleanDestination) {
            $this->line("\nThe backup is located at \"{$this->backupPath}\".");
        }

        $this->info("\nImport finished successfully!");
    }

    /**
     * Get a value from the configuration.
     */
    protected function getConfigValue(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->config, $key, $default);
    }

    /**
     * Get a value from the target environment configuration.
     */
    protected function getEnvironmentConfigValue(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->environmentConfig, $key, $default);
    }

    /**
     * Ensure a directory exists.
     *
     * @throws ImportEnvironmentException
     */
    protected function ensureDirectoryExists(string $path): void
    {
        if (!File::exists($path) && !File::makeDirectory($path, recursive: true)) {
            throw new ImportEnvironmentException("Failed to create cache directory \"{$path}\"");
        }
    }

    protected function sshAuth(): string
    {
        if ($sshPassword = $this->getEnvironmentConfigValue('ssh_password')) {
            return "-p {$sshPassword}";
        }

        if ($sshKey = $this->getEnvironmentConfigValue('ssh_key')) {
            return "-i {$sshKey}";
        }

        return '';
    }

    protected function dbUseSsh(): bool
    {
        return (bool) $this->getEnvironmentConfigValue('db_use_ssh', false);
    }

    protected function dbSshTunnelPort(): string
    {
        return $this->getEnvironmentConfigValue('db_ssh_tunnel_port', '3307');
    }

    protected function dbPort(): string
    {
        return $this->getEnvironmentConfigValue('db_port', '3306');
    }

    protected function sendSuccessNotification(): void
    {
        $enabled = (bool) config('environment-importer.notifications.types.import_succeeded', false);
        $recipients = config('environment-importer.notifications.routes.mail', []);

        if (!$enabled || empty($recipients)) {
            return;
        }

        Notification::route('mail', $recipients)
            ->notify(new ImportSucceeded($this->startedAt, now()));
    }

    protected function sendFailureNotification(Exception $exception): void
    {
        $enabled = (bool) config('environment-importer.notifications.types.import_failed', false);
        $recipients = config('environment-importer.notifications.routes.mail', []);

        if (!$enabled || empty($recipients)) {
            return;
        }

        Notification::route('mail', $recipients)
            ->notify(new ImportFailed($this->startedAt, now(), $exception->getMessage()));
    }
}
