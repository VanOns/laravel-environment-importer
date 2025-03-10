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
    | relative to the root of the project.
    |
    */

    'import_paths' => [
        'storage',
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
