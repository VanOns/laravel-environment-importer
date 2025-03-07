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

            'ssh_host' => env('IMPORT_STAGING_SSH_HOST'),
            'ssh_username' => env('IMPORT_STAGING_SSH_USERNAME'),
            'ssh_base_path' => env('IMPORT_STAGING_SSH_BASE_PATH'),

            'db_host' => env('IMPORT_STAGING_DB_HOST'),
            'db_name' => env('IMPORT_STAGING_DB_NAME'),
            'db_username' => env('IMPORT_STAGING_DB_USERNAME'),
            'db_password' => env('IMPORT_STAGING_DB_PASSWORD'),
            'db_port' => env('IMPORT_STAGING_DB_PORT'),
            'db_use_ssh' => (bool) env('IMPORT_STAGING_DB_USE_SSH', false),

        ],

        'production' => [

            'ssh_host' => env('IMPORT_PRODUCTION_SSH_HOST'),
            'ssh_username' => env('IMPORT_PRODUCTION_SSH_USERNAME'),
            'ssh_base_path' => env('IMPORT_PRODUCTION_SSH_BASE_PATH'),

            'db_host' => env('IMPORT_PRODUCTION_DB_HOST'),
            'db_name' => env('IMPORT_PRODUCTION_DB_NAME'),
            'db_username' => env('IMPORT_PRODUCTION_DB_USERNAME'),
            'db_password' => env('IMPORT_PRODUCTION_DB_PASSWORD'),
            'db_port' => env('IMPORT_PRODUCTION_DB_PORT'),
            'db_use_ssh' => (bool) env('IMPORT_PRODUCTION_DB_USE_SSH', false),

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

    'db_dump_binary_path' => env('IMPORT_DB_DUMP_BINARY_PATH', '/usr/bin'),

    /*
    |--------------------------------------------------------------------------
    | Backup Path
    |--------------------------------------------------------------------------
    |
    | Here you can define the path to where the backups will be stored. The path
    | is relative to the root of the project.
    |
    */

    'backup_path' => env('IMPORT_BACKUP_PATH', '.import'),

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
    | Preserve Users If Email Contains
    |--------------------------------------------------------------------------
    |
    | Here you can define any strings that will be used to determine if a user
    | should be preserved or anonymized. This is done by taking a user's email
    | and checking if it matches any of the strings defined below.
    |
    */

    'preserve_users_if_email_contains' => [],

    /*
    |--------------------------------------------------------------------------
    | Rsync Exclude Paths
    |--------------------------------------------------------------------------
    |
    | Here you can define any paths that should be excluded by rsync when
    | syncing files.
    |
    */

    'rsync_exclude_paths' => [
        'cache/***',
        'storage/framework/***',
    ],

];
