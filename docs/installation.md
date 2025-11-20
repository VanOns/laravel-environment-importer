# Installation

First, install the package via Composer:

```bash
composer require van-ons/laravel-environment-importer
```

Then, publish the configuration file:

```bash
php artisan vendor:publish --tag="environment-importer-config"
```

Next, open the configuration file (`config/environment-importer.php`) and adjust it to your needs.

## Configuration

While the configuration file contains explanations for all options, some options need further explanation.

### `db_use_ssh`

If you want to use an SSH tunnel to connect to your database, set this option to `true`. This will start an SSH tunnel
using the `db_ssh_*` options. By default, the tunnel uses port `3307`, but you can change this using the `db_ssh_tunnel_port`
option. If your SSH key needs an approval step (for example via 1Password), the command waits for the tunnel to become
available for up to `db_ssh_tunnel_timeout` seconds before failing.
