<?php

namespace VanOns\LaravelEnvironmentImporter\Commands;

use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use VanOns\LaravelEnvironmentImporter\Exceptions\ImportEnvironmentException;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Exceptions\CannotSetParameter;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\select;

class ImportEnvironmentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-environment
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

    protected Carbon $now;

    protected string $target;

    protected array $config;

    protected array $environmentConfig;

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
        $this->now = now();

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
        $this->backupPath = base_path($this->getConfigValue('backup_path', '.import') . '/' . $this->now->format('Y-m-d_H-i-s'));
        $this->ensureDirectoryExists($this->backupPath);
    }

    /**
     * Get the environments that can be imported from.
     */
    protected function getEnvironments(): array
    {
        $keys = [
            'ssh_host',
            'ssh_username',
            'ssh_base_path',
            'db_host',
            'db_name',
            'db_username',
            'db_password',
            'db_port',
            'db_use_ssh',
        ];

        $environments = [];

        foreach ($this->getConfigValue('environments', []) as $environment => $config) {
            // Only add the environment if all keys are present, and don't have an empty value.
            $isValid = collect($keys)->every(fn ($key) => !empty($config[$key]));
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

        $this->dumpLocalDatabase($dumpPath);
        $this->dumpRemoteDatabase($dumpPath, $dumpFile);
        $this->wipeLocalDatabase();
        $this->importDatabaseDump($dumpFile);
        $this->replaceSensitiveDataInDatabase();
        $this->afterDatabaseImport($dumpPath);
        $this->runDatabaseMigrations();

        $this->info('[DB] Database imported.');
    }

    /**
     * Dump the local database.
     */
    protected function dumpLocalDatabase(string $dumpPath): void
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
     */
    protected function dumpRemoteDatabase(string $dumpPath, string $dumpFile): void
    {
        $this->line('[DB] Dumping target database...');

        $this->beforeRemoteDatabaseConnection();

        $exclude = [];
        $files = [];

        $this->line('[DB] Processing sensitive tables...');

        // Dump sensitive tables separately so we only get their CREATE statements, but not their data.
        foreach ($this->getConfigValue('sensitive_tables', []) as $table) {
            $tableDumpFile = "{$dumpPath}/{$this->target}_{$table}.sql";
            $files[] = $tableDumpFile;

            $this->getDatabaseDumpClient()
                ->doNotDumpData()
                ->includeTables([$table])
                ->dumpToFile($tableDumpFile);
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
            $this->dbTunnelProcess = new Process([
                'ssh',
                '-L',
                "{$this->dbSshTunnelPort()}:127.0.0.1:{$this->dbPort()}",
                "{$this->getEnvironmentConfigValue('ssh_username')}@{$this->getEnvironmentConfigValue('ssh_host')}",
                '-N',
                '-f',
            ]);
        }

        if (!$this->dbTunnelProcess->isRunning()) {
            $this->line('[DB] Starting SSH tunnel...');

            $this->dbTunnelProcess->start();

            $this->line('[DB] Waiting for SSH tunnel to start...');

            // Wait for the tunnel to start.
            $tries = 10;
            while (!$this->dbTunnelProcess->isRunning()) {
                if ($tries <= 0) {
                    throw new ImportEnvironmentException('Failed to start SSH tunnel');
                }

                $tries--;
                sleep(2);
            }

            $this->info('[DB] SSH tunnel started.');
        }
    }

    /**
     * Clean up after connecting to the remote database.
     */
    protected function afterRemoteDatabaseConnection(): void
    {
        if (!$this->dbUseSsh() || !$this->dbTunnelProcess?->isRunning()) {
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
     */
    protected function importDatabaseDump(string $dumpFile): void
    {
        $this->line('[DB] Importing database dump...');

        $handle = fopen($dumpFile, 'rb');

        /**
         * The following code will read the dump file line by line and execute each statement, which is useful for large
         * database dumps. The end of each statement is determined by a semicolon. We do it this way because it's a lot
         * less resource-intensive than loading the entire dump file into memory and executing it as a single query.
         */
        $query = '';
        while (($line = fgets($handle)) !== false) {
            $query .= $line;

            // Check if the line ends with a semicolon, meaning it's a complete statement.
            if (str_ends_with(trim($line), ';')) {
                DB::unprepared($query); // Execute the query.
                $query = ''; // Reset for next query.
            }
        }

        fclose($handle);

        $this->info("[DB] Imported database dump from \"{$dumpFile}\".");
    }

    /**
     * Replace sensitive data in the database.
     */
    protected function replaceSensitiveDataInDatabase(): void
    {
        $this->line('[DB] Replacing sensitive data in database...');

        /** @var Authenticatable|Model $user */
        foreach ($this->getUserModel()->query()->cursor() as $user) {
            $data = [];

            /** @phpstan-ignore-next-line */
            $preserveUser = Str::contains($user->email, $this->getConfigValue('preserve_users_if_email_contains'));
            if (!$preserveUser) {
                $name = $this->generateUniqueValue('users', 'first_name', 'User');

                $data['first_name'] = $name;
                $data['last_name'] = $name;
                $data['email'] = strtolower($name) . '@example.com';
            }

            // In local environments we want to reset the password to 'password'.
            if (app()->isLocal()) {
                $data['password'] = Hash::make('password');
            }

            // Only update if any data changed.
            if (!empty($data)) {
                /** @phpstan-ignore-next-line */
                DB::table('users')->where('id', $user->id)->update($data);
            }

            $this->maybeHandleStatamicTwoFactor($user);
        }

        $this->info('[DB] Replaced sensitive data in database.');
    }

    /**
     * If the project is using Statamic and the Two Factor plugin from MityDigital, disable two-factor authentication.
     */
    protected function maybeHandleStatamicTwoFactor(Authenticatable|Model $user): void
    {
        if (!class_exists('Statamic\Facades\User') || !class_exists('MityDigital\StatamicTwoFactor\Actions\DisableTwoFactorAuthentication')) {
            return;
        }

        // Disable two-factor authentication so you log in as the user.
        app(\MityDigital\StatamicTwoFactor\Actions\DisableTwoFactorAuthentication::class)(\Statamic\Facades\User::fromUser($user));
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

        foreach ($this->getConfigValue('import_paths', []) as $path) {
            $this->importSingle($path);
        }

        $this->createFrameworkFolders();

        $this->info('[Files] Files imported.');
    }

    /**
     * Import a single file or directory.
     *
     * @throws ImportEnvironmentException
     */
    protected function importSingle(string $path): void
    {
        $this->line("[Files] Importing \"{$path}\"...");

        // Make sure the source ends with a /.
        $source = $this->getEnvironmentConfigValue('ssh_base_path') . "/{$path}" . (str_ends_with($path, '/') ? '' : '/');
        $destination = base_path($path);

        $this->backupDestination($destination, $path);

        $excludes = '';
        if (!empty($excludePaths = $this->getConfigValue('rsync_exclude_paths', []))) {
            $excludes = '--exclude="' . implode('" --exclude="', $excludePaths) . '"';
        }

        $command = sprintf(
            'rsync -zaHLK --progress --stats -e "ssh" %s %s@%s:%s %s',
            $excludes,
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

            // Throw an error if the process fails.
            if ($type === Process::ERR) {
                throw new ImportEnvironmentException("rsync failed: {$buffer}");
            }
        });

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
     * Generate a unique value for a column in a table.
     */
    protected function generateUniqueValue(string $table, string $column, string $value): string
    {
        $uniqueValue = uniqid($value . '_');

        while (DB::table($table)->where($column, $uniqueValue)->exists()) {
            $uniqueValue = uniqid($value . '_');
        }

        return $uniqueValue;
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

    /**
     * Get the user model.
     */
    protected function getUserModel(): Authenticatable|Model
    {
        $model = config('auth.providers.users.model');

        return app($model);
    }

    protected function dbUseSsh(): bool
    {
        return (bool) $this->getEnvironmentConfigValue('db_use_ssh', false);
    }

    protected function dbSshTunnelPort(): int
    {
        return (int) $this->getEnvironmentConfigValue('db_ssh_tunnel_port', 3307);
    }

    protected function dbPort(): int
    {
        return (int) $this->getEnvironmentConfigValue('db_port');
    }
}
