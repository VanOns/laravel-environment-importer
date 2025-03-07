<?php

namespace VanOns\LaravelEnvironmentImporter\Commands;

use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
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

class ImportEnvironment extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-environment
                            {--target= : The target environment to import}
                            {--safe : Skip all prompts and keep all files after import}
                            {--clean : Skip all prompts and clean up all files after import}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import the target environment and set it up locally';

    protected bool $safe = false;

    protected bool $clean = false;

    protected Carbon $now;

    protected string $target;

    protected array $config;

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

        $this->now = now();

        $environments = $this->getEnvironments();
        $this->target = $this->option('target') ?? select('Select the target environment', $environments);
        if (!array_key_exists($this->target, config('import.environments', []))) {
            throw new ImportEnvironmentException("The \"{$this->target}\" environment does not exist");
        }

        $this->config = config("import.environments.{$this->target}", []);
        if (empty($this->config)) {
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
        $this->backupPath = base_path(config('import.backup_path', '.import') . '/' . $this->now->format('Y-m-d_H-i-s'));
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

        foreach (config('import.environments', []) as $environment => $config) {
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

        // Dump sensitive tables separately so we only get their CREATE statements, but not their data.
        foreach (config('import.sensitive_tables', []) as $table) {
            $tableDumpFile = "{$dumpPath}/{$this->target}_{$table}.sql";
            $files[] = $tableDumpFile;

            $this->getDatabaseDumpClient()
                ->doNotDumpData()
                ->includeTables([$table])
                ->dumpToFile($tableDumpFile);
        }

        $baseDumpFile = "{$dumpPath}/{$this->target}_base.sql";
        $this->getDatabaseDumpClient()
            ->excludeTables($exclude)
            ->dumpToFile($baseDumpFile);

        $this->afterRemoteDatabaseConnection();

        $finalDumpFile = file_get_contents($baseDumpFile) . implode("\n", array_map('file_get_contents', $files));
        File::put($dumpFile, $finalDumpFile);

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
            $this->config['db_use_ssh'] => 3307,
            default => $this->config['db_port'],
        };

        return MySql::create()
            ->setHost($local ? DB::getConfig('host') : $this->config['db_host'])
            ->setDbName($local ? DB::getConfig('database') : $this->config['db_name'])
            ->setUserName($local ? DB::getConfig('username') : $this->config['db_username'])
            ->setPassword($local ? DB::getConfig('password') : $this->config['db_password'])
            ->setPort($port)
            ->setDumpBinaryPath(config('import.db_dump_binary_path', '/usr/bin'));
    }

    /**
     * Set up before connecting to the remote database.
     */
    protected function beforeRemoteDatabaseConnection(): void
    {
        if (!$this->config['db_use_ssh']) {
            return;
        }

        if (!$this->dbTunnelProcess) {
            $this->dbTunnelProcess = new Process([
                'ssh', '-L', "3307:127.0.0.1:{$this->config['db_port']}", "{$this->config['ssh_username']}@{$this->config['ssh_host']}", '-N', '-f',
            ]);
        }

        if (!$this->dbTunnelProcess->isRunning()) {
            $this->line('[DB] Starting SSH tunnel...');

            $this->dbTunnelProcess->start();

            $this->line('[DB] Waiting for tunnel to start...');

            sleep(2);
        }
    }

    /**
     * Clean up after connecting to the remote database.
     */
    protected function afterRemoteDatabaseConnection(): void
    {
        if (!$this->config['db_use_ssh'] || !$this->dbTunnelProcess?->isRunning()) {
            return;
        }

        $this->line('[DB] Stopping SSH tunnel...');

        $this->dbTunnelProcess->stop();
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
            $preserveUser = Str::contains($user->email, config('import.preserve_users_if_email_contains'));
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
        $this->line('[Files] Importing files...');

        $this->importSingle('content/trees');
        $this->importSingle('storage');
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
        $source = $this->config['ssh_base_path'] . "/{$path}" . (str_ends_with($path, '/') ? '' : '/');
        $destination = base_path($path);

        $this->backupDestination($destination, $path);

        $excludes = '';
        if (!empty($excludePaths = config('import.rsync_exclude_paths', []))) {
            $excludes = '--exclude="' . implode('" --exclude="', $excludePaths) . '"';
        }

        $command = sprintf(
            'rsync -zaHLK --progress --stats -e "ssh" %s %s@%s:%s %s',
            $excludes,
            $this->config['ssh_username'],
            $this->config['ssh_host'],
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
}
