<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Environments
    |--------------------------------------------------------------------------
    |
    | Here you can define any environment you want to be able to import from.
    |
    */

    'environments' => [
        'staging' => [
            'ssh_host' => env('LEI_STAGING_SSH_HOST'),
            'ssh_username' => env('LEI_STAGING_SSH_USERNAME'),
            'ssh_key' => env('LEI_STAGING_SSH_KEY', '~/.ssh/id_rsa'),
            'ssh_password' => env('LEI_STAGING_SSH_PASSWORD'), // Overrules ssh_key if present
            'ssh_base_path' => env('LEI_STAGING_SSH_BASE_PATH'),

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
            'ssh_password' => env('LEI_PRODUCTION_SSH_PASSWORD'), // Overrules ssh_key if present
            'ssh_base_path' => env('LEI_PRODUCTION_SSH_BASE_PATH'),

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
    | Here you can define the path to where the binary lives, which will be used to
    | dump the database.
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
    | Here you can define the path to where the binary lives, which will
    | be used to import the database.
    |
    | Example binaries: mysql, mongoimport, mariadb, psql, etc.
    |
    | NOTE: Don't include the binary itself in the path.
    |
    */

    'db_import_binary_path' => env('LEI_DB_IMPORT_BINARY_PATH', '/usr/bin'),

    /*
    |--------------------------------------------------------------------------
    | Backup Path
    |--------------------------------------------------------------------------
    |
    | Here you can define the path to where the backups will be stored. The path
    | is relative to the root of the project.
    |
    */

    'backup_path' => env('LEI_BACKUP_PATH', '.import'),

    /*
    |--------------------------------------------------------------------------
    | Import Paths
    |--------------------------------------------------------------------------
    |
    | Here you can define the paths that should be imported. Each path is
    | relative to the root of the project. For each import path you can define
    | an array of excludes, which are paths that should be skipped. If you do
    | not want to exclude anything, you can just define the path as a string.
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
    | Here you can define any tables that should have their data cleared when
    | dumping the database.
    | This could for example be used for tables containing sensitive data.
    |
    */

    'empty_tables' => [],

    /*
    |--------------------------------------------------------------------------
    | Persist Tables
    |--------------------------------------------------------------------------
    |
    | Here you can define any tables that should have their data persist when
    | dumping the database.
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
    | Here you can define any processors that should be run on the database after
    | building the dump file.
    |
    */

    'database_processors' => [],

    /*
    |--------------------------------------------------------------------------
    | Data Processors
    |--------------------------------------------------------------------------
    |
    | Here you can define any processors that should be run on the data when
    | importing it into the local environment.
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
    | Here you can define the notifications that should be sent in specific
    | situations. You can define the types of notifications and the routes
    | that should be used to send them.
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
