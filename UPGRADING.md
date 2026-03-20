# Upgrading

We aim to make upgrading between versions as smooth as possible, but sometimes it involves specific steps to be taken.
This document will outline those steps. And as much as we try to cover all cases, we might miss some. If you come
across such a case, please let us know by [opening an issue][issues], or by adding it yourself and creating a pull request.

## v0.10.0

* The `db_no_lock_strategy` configuration option was added. This allows for applying a lock-avoidance strategy when dumping
  the remote MySQL/MariaDB database to prevent metadata locks from blocking writes on the remote server during the dump.
  This flag is only supported by MySQL/MariaDB.
  
  Valid values for the option are:
  * `single_transaction` (default): Wraps entire dump in a `REPEATABLE READ` transaction — no locks, consistent snapshot. InnoDB only.
  * `skip_lock_tables`: Dumps each table independently without locking. Works for all engines but dump is not transactionally consistent.
  * `null`: Disables the feature entirely. Preserves old behavior — no flag passed to dumper.

* The `skip_ssl_local` configuration option was renamed to `db_skip_ssl_local` to be more consistent with the `db_skip_ssl` option.

## v0.9.0

* Functionality was added that allows running commands after the import process is finished. To use this functionality,
  use the `post_import_commands` key to your config file, and add the commands you want to run as an array of strings:

  ```php
  'post_import_commands' => [
      'php artisan db:seed --force',
  ],
  ```

## v0.8.5

* The `skip_ssl_local` configuration option was added. This allows for skipping SSL when connecting to the local database.
  This flag is only supported by MySQL/MariaDB.

## v0.8.1

* The `db_skip_ssl` configuration option was added to `environments.*`. This allows for skipping SSL when connecting to
  the remote database. This flag is only supported by MySQL.

## v0.8.0

* The namespace for the `AnonymizeUsers` processor was changed. Make sure to update your config file accordingly:

  ```diff
  - \VanOns\LaravelEnvironmentImporter\Processors\AnonymizeUsers::class
  + \VanOns\LaravelEnvironmentImporter\Processors\Data\AnonymizeUsers::class
  ```

* The `db_timeout` configuration option was added (default: `60`, same as the default shell command timeout).
  Set the option to `null` to disable the timeout.

* The `db_ssh_tunnel_timeout` configuration option was added (default: `30` seconds). This sets the timeout for establishing
  an SSH tunnel to the remote database server. Set the option to `null` to disable the timeout.

* The `db_type` configuration option was added to `environments.*`. This allows specifying the database type of the remote
  environment (`mysql` (default), `mariadb`, `mongodb`, `pgsql` or `sqlite`).

## v0.6.0

* The configuration for the `AnonymizeUsers` processor was changed. Make sure to update your config file accordingly, so
  that it follows the following format:

  ```php
  \VanOns\LaravelEnvironmentImporter\Processors\Data\AnonymizeUsers::class => [
      'preserve_emails' => ['@example.com', 'john@doe.com'],
      'email_domain' => 'example.com',
      'password_override' => 'password',
  ]
  ```

## v0.4.0

* The `sensitive_tables` config key was renamed to `empty_tables`. A fallback has been put in place that will use the
old key, in case the new key is empty, but it is recommended to update your config.

[issues]: https://github.com/VanOns/laravel-environment-importer/issues
