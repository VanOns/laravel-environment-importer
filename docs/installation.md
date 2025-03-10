# Installation

While the package is for internal use only, you can install it from GitHub. Add the following to your `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/VanOns/laravel-environment-importer"
        }
    ],
}
```

Then, install the package:

```bash
composer require van-ons/laravel-environment-importer
```

Next, publish the configuration file:

```bash
php artisan vendor:publish --tag="translations-sync-config"
```

Finally, open the configuration file (`config/environment-importer.php`) and adjust it to your needs.
