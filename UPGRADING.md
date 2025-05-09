# Upgrading

We aim to make upgrading between versions as smooth as possible, but sometimes it involves specific steps to be taken.
This document will outline those steps. And as much as we try to cover all cases, we might miss some. If you come
across such a case, please let us know by [opening an issue][issues], or by adding it yourself and creating a pull request.

<!-- EXAMPLE -->
<!--
# v1 to v2

* Remove the `foo` column from the `bar` table.
* Add the `baz` column to the `bar` table.
* Run `php artisan migrate` to update the database.
-->

## v0.5.0

* The configuration for the `AnonymizeUsers` processor has changed. Make sure to update your config file accordingly, so
  that it follows the following format:
  ```php
  \VanOns\LaravelEnvironmentImporter\Processors\AnonymizeUsers::class => [
      'preserve_emails' => ['@example.com', 'john@doe.com'],
      'email_domain' => 'example.com',
      'password_override' => 'password',
  ]
  ```

## v0.4.0

* The `sensitive_tables` config key was renamed to `empty_tables`. A fallback has been put in place that will use the
old key, in case the new key is empty, but it is recommended to update your config.

[issues]: https://github.com/VanOns/laravel-environment-importer/issues
