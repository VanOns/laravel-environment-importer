## Laravel Environment Importer (`van-ons/laravel-environment-importer`)

Imports a remote environment (staging, production) into the environment the command runs in: it dumps the remote database (optionally through an SSH tunnel), replaces the local database with it, copies files with `rsync` over SSH, anonymizes users, runs migrations and clears caches. Everything happens in one Artisan command, `environment:import`. The service provider is auto-discovered.

### Setup

@verbatim
<code-snippet name="Install" lang="bash">
composer require van-ons/laravel-environment-importer
php artisan vendor:publish --tag=environment-importer-config
</code-snippet>

<code-snippet name=".env per environment (prefix LEI_STAGING_ or LEI_PRODUCTION_)" lang="dotenv">
LEI_STAGING_SSH_HOST=staging.example.com
LEI_STAGING_SSH_USERNAME=forge
LEI_STAGING_SSH_KEY=~/.ssh/id_rsa
LEI_STAGING_SSH_BASE_PATH=/home/forge/example.com
LEI_STAGING_DB_TYPE=mysql
LEI_STAGING_DB_HOST=127.0.0.1
LEI_STAGING_DB_NAME=example
LEI_STAGING_DB_USERNAME=forge
LEI_STAGING_DB_PASSWORD=secret
LEI_STAGING_DB_PORT=3306
LEI_STAGING_DB_USE_SSH=true
</code-snippet>
@endverbatim

The machine running the import needs `ssh` (plus `sshpass` when an environment uses `ssh_password`), `rsync`, the dump binary for the database type (`mysqldump`, `mariadb-dump`, `pg_dump`, ...) in `db_dump_binary_path`, and `mysql` (MySQL and MariaDB) or `psql` in `db_import_binary_path`. Both default to `/usr/bin` and hold the directory only.

### Command

@verbatim
<code-snippet name="environment:import" lang="bash">
php artisan environment:import                          # asks for the environment and every choice
php artisan environment:import --target=staging --safe  # no prompts; back up the destination, keep dumps and files
php artisan environment:import --target=staging --clean # no prompts; no backup, delete the dumps afterwards
php artisan environment:import --target=staging --safe --skip-files   # database only
php artisan environment:import --target=staging --safe --skip-db      # files only
</code-snippet>
@endverbatim

What it does, in order: back up the local database to `{backup_path}/{Y-m-d_H-i-s}/db/local.sql`, dump the remote database (`persist_tables` taken from the local database, `empty_tables` as structure only), run the database processors on the dump, `db:wipe --force` the local database, import the dump, run the data processors, `migrate --force`, `rsync` each of `import_paths`, `optimize:clear`, then run `post_import_commands`. A failure sends the `import_failed` notification and stops the SSH tunnel.

### Config (`config/environment-importer.php`)

| Key | Default | Notes |
|---|---|---|
| `environments.{name}` | `staging`, `production` | `ssh_host`, `ssh_username`, `ssh_key` / `ssh_password`, `ssh_base_path`, `db_type` (`mysql`, `mariadb`, `mongodb`, `pgsql`, `sqlite`), `db_host`, `db_name`, `db_username`, `db_password`, `db_port`, `db_use_ssh`, `db_ssh_tunnel_port` (`3307`), `db_skip_ssl` (MySQL/MariaDB). Add more environments under any name. |
| `db_dump_binary_path` / `db_import_binary_path` | `/usr/bin` | Env `LEI_DB_DUMP_BINARY_PATH`, `LEI_DB_IMPORT_BINARY_PATH`. |
| `db_timeout` | `60` | Seconds; `null` for none. Also the timeout of each `rsync`. |
| `db_ssh_tunnel_timeout` | `30` | Seconds to wait for the tunnel, e.g. while 1Password asks for approval. |
| `db_skip_ssl_local` | `false` | For the local backup dump (MySQL/MariaDB). |
| `db_no_lock_strategy` | `single_transaction` | Remote MySQL/MariaDB dumps: `single_transaction` (InnoDB), `skip_lock_tables` or `null`. |
| `backup_path` | `.import` | Relative to the project root. |
| `import_paths` | `['storage' => ['excludes' => ['framework/***']]]` | Paths relative to the project root; a plain string for no excludes. |
| `empty_tables` | `[]` | Imported without data. (`sensitive_tables` is still read as a fallback.) |
| `persist_tables` | `[]` | Keep the local data of these tables. |
| `database_processors` | `FixCommonMySQLErrors`, `FixMariadbMysqlGeneratedColumnMismatch` | Run on the SQL dump file. |
| `data_processors` | `AnonymizeUsers` | Run per table after the import. |
| `post_import_commands` | `[]` | Shell command lines, or an Artisan command class => its arguments. |
| `notifications.types.import_succeeded` / `import_failed` | `false` | Env `LEI_NOTIFY_IMPORT_SUCCEEDED`, `LEI_NOTIFY_IMPORT_FAILED`. |
| `notifications.routes.mail` | `[]` | Env `LEI_NOTIFY_MAIL`, comma-separated. |

### Processors

A processor is listed as a class, or as class => options. A data processor extends `Processors\Data\DataProcessor` (`tables(): array` with table names or `'*'`, `process()`, `$this->table`, `$this->options`); a database processor extends `Processors\Database\DatabaseProcessor` (`applies()`, `process()` editing `$this->dumpFile`; its options also get `source_db_type` and `target_db_type`). Anything else throws `ImportEnvironmentException`.

@verbatim
<code-snippet name="Processors and post-import commands" lang="php">
'data_processors' => [
    \VanOns\LaravelEnvironmentImporter\Processors\Data\AnonymizeUsers::class => [
        'preserve_emails' => ['@van-ons.nl'],   // matched with Str::contains
        'email_domain' => 'example.com',
        'password_override' => 'password',      // hashed; empty keeps the passwords
        'handle_statamic_2fa' => true,
    ],
    \App\Import\ClearApiTokens::class,
],

'post_import_commands' => [
    'php artisan db:seed --class=LocalSeeder --force',
    \App\Console\Commands\ReindexSearch::class => ['--fresh' => true],
],
</code-snippet>

<code-snippet name="A data processor" lang="php">
use Illuminate\Support\Facades\DB;
use VanOns\LaravelEnvironmentImporter\Processors\Data\DataProcessor;

class ClearApiTokens extends DataProcessor
{
    public function tables(): array
    {
        return ['personal_access_tokens'];
    }

    public function process(): void
    {
        DB::table($this->table)->delete();
    }
}
</code-snippet>
@endverbatim

### Conventions and gotchas

- **Destructive and local only.** The command wipes the database of the app it runs in and overwrites the `import_paths` directories; it has no check against running on a server. Never run it unless the user asks, never on a remote environment, and pass `--target` with `--safe` or `--clean` when no one can answer the prompts.
- The prompt only offers an environment whose `ssh_host`, `ssh_username`, `ssh_base_path`, `db_type`, `db_host`, `db_name`, `db_username`, `db_password` and `db_port` are all filled in, plus `ssh_key` or `ssh_password`; with none complete the command stops with "No environments found to import from". `--target` is only checked against the configured names, so an incomplete one starts and fails later.
- With `db_use_ssh`, the tunnel forwards local port `db_ssh_tunnel_port` to `127.0.0.1:db_port` on the SSH host and the dump connects to `db_host` on the tunnel port, so set `db_host` to `127.0.0.1`.
- `ssh_password` takes precedence over `ssh_key` and goes to `sshpass` through the `SSHPASS` environment variable, so `sshpass` must be installed.
- The local import goes by the default connection's name: `mysql`, `mariadb`, `sqlite` (the dump file is copied over the database file) or `pgsql`; any other name, `mongodb` included, throws.
- `AnonymizeUsers` runs on the `users` table through `auth.providers.users.model` and, with the query builder, replaces `email` and whichever of `first_name`, `last_name` and `name` the table has.
- A `post_import_commands` string runs as a shell command line, so Artisan commands are written as `php artisan ...`; only an Artisan command class runs through `Artisan::call()`.
- Dumps and backups (including `local.sql`, with personal data) land under `backup_path` in the project root: keep that directory git-ignored. With `--safe`, they stay there.
- Large `rsync` runs are cut off after `db_timeout` seconds; raise it or set it to `null`.
