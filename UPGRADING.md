# Upgrading

We aim to make upgrading between versions as smooth as possible, but sometimes it involves specific steps to be taken.
This document will outline those steps. And as much as we try to cover all cases, we might miss some. If you come
across such a case, please let us know by [opening an issue][issues], or by adding it yourself and creating a pull request.

## v0.8.0

* The namespace for the `AnonymizeUsers` processor has changed. Make sure to update your config file accordingly:

  ```diff
  - \VanOns\LaravelEnvironmentImporter\Processors\AnonymizeUsers::class
  + \VanOns\LaravelEnvironmentImporter\Processors\Data\AnonymizeUsers::class
  ```

## v0.6.0

* The configuration for the `AnonymizeUsers` processor has changed. Make sure to update your config file accordingly, so
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
