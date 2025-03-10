<?php

namespace VanOns\LaravelEnvironmentImporter;

use Illuminate\Support\ServiceProvider;
use VanOns\LaravelEnvironmentImporter\Commands\ImportEnvironmentCommand;

class LaravelEnvironmentImporterServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes(
            paths: [
                __DIR__ . '/../config/environment-importer.php' => config_path('environment-importer.php'),
            ],
            groups: 'laravel-environment-importer-config'
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportEnvironmentCommand::class,
            ]);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/environment-importer.php', 'environment-importer'
        );
    }
}
