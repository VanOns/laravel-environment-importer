# Installation

While the package is for internal use only, you can install it from GitHub. Add the following to your `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/VanOns/laravel-environment-importer"
        }
    ]
}
```

Then, install the package:

```bash
composer require van-ons/laravel-environment-importer
```

Next, publish the configuration file:

```bash
php artisan vendor:publish --tag="environment-importer-config"
```

Finally, open the configuration file (`config/environment-importer.php`) and adjust it to your needs.

## Configuration

While the configuration file contains explanations for all options, some options need further explanation.

### `db_use_ssh`

If you want to use an SSH tunnel to connect to your database, set this option to `true`. This will start an SSH tunnel
using the `db_ssh_*` options. By default, the tunnel uses port `3307`, but you can change this using the `db_ssh_tunnel_port` option.
