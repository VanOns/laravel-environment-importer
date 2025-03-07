<?php

namespace VanOns\LaravelEnvironmentImporter;

use Illuminate\Support\ServiceProvider;
use VanOns\LaravelEnvironmentImporter\Commands\ImportEnvironment;

class LaravelEnvironmentImporterServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes(
            paths: [
                __DIR__ . '/../config/import.php' => config_path('import.php'),
            ],
            groups: 'skeleton-config'
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportEnvironment::class,
            ]);
        }
    }

    public function register(): void
    {
        //
    }
}
