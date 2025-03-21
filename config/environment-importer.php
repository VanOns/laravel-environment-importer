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
            'db_port' => env('LEI_STAGING_DB_PORT'),
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
            'db_port' => env('LEI_PRODUCTION_DB_PORT'),
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
    | dump the database. On MacOS you can install this using Homebrew:
    | https://formulae.brew.sh/formula/mysql-client
    |
    | NOTE: Don't include the binary itself in the path.
    |
    */

    'db_dump_binary_path' => env('LEI_DB_DUMP_BINARY_PATH', '/usr/bin'),

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
    | Sensitive Tables
    |--------------------------------------------------------------------------
    |
    | Here you can define any tables that should have their data skipped when
    | dumping the database.
    |
    */

    'sensitive_tables' => [],

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
        \VanOns\LaravelEnvironmentImporter\Processors\AnonymizeUsers::class,

        // If you want to provide patterns for users to be preserved:
        // \VanOns\LaravelEnvironmentImporter\Processors\AnonymizeUsers::class => ['@example.com'],
    ],

];
