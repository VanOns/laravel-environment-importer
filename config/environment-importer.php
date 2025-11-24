<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Environments
    |--------------------------------------------------------------------------
    |
    | Environments that should be available for import.
    |
    | Each environment supports the following settings:
    | - ssh_host: The SSH host to connect to.
    | - ssh_username: The SSH username to use.
    | - ssh_key: The SSH private key to use (optional if ssh_password is used).
    | - ssh_password: The SSH password to use (overrules ssh_key if present).
    | - ssh_base_path: The base path of the project on the remote server.
    |
    | - db_type: The database connection type. Supported types: `mysql` (default), `mariadb`, `mongodb`, `pgsql` and `sqlite`.
    | - db_host: The database host.
    | - db_name: The database name.
    | - db_username: The database username.
    | - db_password: The database password.
    | - db_port: The database port.
    | - db_use_ssh: Whether to use an SSH tunnel to connect to the database.
    | - db_ssh_tunnel_port: The local port to use for the SSH tunnel.
    |
    */

    'environments' => [
        'staging' => [
            'ssh_host' => env('LEI_STAGING_SSH_HOST'),
            'ssh_username' => env('LEI_STAGING_SSH_USERNAME'),
            'ssh_key' => env('LEI_STAGING_SSH_KEY', '~/.ssh/id_rsa'),
            'ssh_password' => env('LEI_STAGING_SSH_PASSWORD'),
            'ssh_base_path' => env('LEI_STAGING_SSH_BASE_PATH'),

            'db_type' => env('LEI_STAGING_DB_TYPE', 'mysql'),
            'db_host' => env('LEI_STAGING_DB_HOST'),
            'db_name' => env('LEI_STAGING_DB_NAME'),
            'db_username' => env('LEI_STAGING_DB_USERNAME'),
            'db_password' => env('LEI_STAGING_DB_PASSWORD'),
            'db_port' => env('LEI_STAGING_DB_PORT', '3306'),
            'db_use_ssh' => (bool) env('LEI_STAGING_DB_USE_SSH', false),
            'db_ssh_tunnel_port' => env('LEI_STAGING_DB_SSH_TUNNEL_PORT', '3307'),
        ],

        'production' => [
            'ssh_host' => env('LEI_PRODUCTION_SSH_HOST'),
            'ssh_username' => env('LEI_PRODUCTION_SSH_USERNAME'),
            'ssh_key' => env('LEI_PRODUCTION_SSH_KEY', '~/.ssh/id_rsa'),
            'ssh_password' => env('LEI_PRODUCTION_SSH_PASSWORD'),
            'ssh_base_path' => env('LEI_PRODUCTION_SSH_BASE_PATH'),

            'db_type' => env('LEI_PRODUCTION_DB_TYPE', 'mysql'),
            'db_host' => env('LEI_PRODUCTION_DB_HOST'),
            'db_name' => env('LEI_PRODUCTION_DB_NAME'),
            'db_username' => env('LEI_PRODUCTION_DB_USERNAME'),
            'db_password' => env('LEI_PRODUCTION_DB_PASSWORD'),
            'db_port' => env('LEI_PRODUCTION_DB_PORT', '3306'),
            'db_use_ssh' => (bool) env('LEI_PRODUCTION_DB_USE_SSH', false),
            'db_ssh_tunnel_port' => env('LEI_PRODUCTION_DB_SSH_TUNNEL_PORT', '3307'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Dump Binary Path
    |--------------------------------------------------------------------------
    |
    | Path to where the binary lives, which will be used to dump the database.
    |
    | Example binaries: mysqldump, mongodump, mariadb-dump, pg_dump, etc.
    |
    | NOTE: Don't include the binary itself in the path.
    |
    */

    'db_dump_binary_path' => env('LEI_DB_DUMP_BINARY_PATH', '/usr/bin'),

    /*
    |--------------------------------------------------------------------------
    | Database Import Binary Path
    |--------------------------------------------------------------------------
    |
    | Path to where the binary lives, which will be used to import the database.
    |
    | Example binaries: mysql, mongoimport, mariadb, psql, etc.
    |
    | NOTE: Don't include the binary itself in the path.
    |
    */

    'db_import_binary_path' => env('LEI_DB_IMPORT_BINARY_PATH', '/usr/bin'),

    /*
    |--------------------------------------------------------------------------
    | Database Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout (in seconds) for database operations. This includes dumping and
    | importing the database.
    |
    | Set to `null` to disable the timeout.
    |
    */

    'db_timeout' => env('LEI_DB_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | SSH Tunnel Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum number of seconds to wait for the SSH tunnel (and any external
    | credential prompts such as 1Password) to become available.
    |
    | Set to `null` to disable the timeout.
    |
    */

    'db_ssh_tunnel_timeout' => env('LEI_DB_SSH_TUNNEL_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Backup Path
    |--------------------------------------------------------------------------
    |
    | Path to where the backups will be stored. The path is relative to the
    | root of the project.
    |
    */

    'backup_path' => env('LEI_BACKUP_PATH', '.import'),

    /*
    |--------------------------------------------------------------------------
    | Import Paths
    |--------------------------------------------------------------------------
    |
    | Paths that should be imported. Each path is relative to the root of
    | the project. For each import path you can define an array of excludes,
    | which are paths that should be skipped. If you do not want to exclude
    | anything, you can just define the path as a string.
    |
    */

    'import_paths' => [
        'storage' => [
            'excludes' => ['framework/***'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Empty Tables
    |--------------------------------------------------------------------------
    |
    | Tables that should have their data cleared when dumping the database.
    | This could for example be used for tables containing sensitive data.
    |
    */

    'empty_tables' => [],

    /*
    |--------------------------------------------------------------------------
    | Persist Tables
    |--------------------------------------------------------------------------
    |
    | Tables that should have their data persist when dumping the database.
    | This could for example be used for tables containing configuration for
    | a specific environment, that should not be imported to a different
    | environment.
    |
    */

    'persist_tables' => [],

    /*
    |--------------------------------------------------------------------------
    | Database Processors
    |--------------------------------------------------------------------------
    |
    | Processors that should be run on the database after building the dump file.
    |
    */

    'database_processors' => [
        \VanOns\LaravelEnvironmentImporter\Processors\Database\FixCommonMySQLErrors::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Processors
    |--------------------------------------------------------------------------
    |
    | Processors that should be run on the data when importing it into the
    | local environment.
    |
    */

    'data_processors' => [
        \VanOns\LaravelEnvironmentImporter\Processors\Data\AnonymizeUsers::class,

        // This processor supports the following options:
        //\VanOns\LaravelEnvironmentImporter\Processors\Data\AnonymizeUsers::class => [
        //    'preserve_emails' => ['@example.com', 'john@doe.com'],
        //    'email_domain' => 'example.com',
        //    'password_override' => 'password',
        //],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Notifications that should be sent in specific situations. You can define
    | the types of notifications and the routes that should be used to send them.
    |
    */

    'notifications' => [
        'types' => [
            'import_succeeded' => env('LEI_NOTIFY_IMPORT_SUCCEEDED', false),
            'import_failed' => env('LEI_NOTIFY_IMPORT_FAILED', false),
        ],

        'routes' => [
            /**
             * Comma-separated list of email addresses to send the notifications to. Leave empty to disable.
             */
            'mail' => explode(',', env('LEI_NOTIFY_MAIL', '')),
        ],
    ],

];
