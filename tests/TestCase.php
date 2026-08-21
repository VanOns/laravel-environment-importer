<?php

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use VanOns\LaravelEnvironmentImporter\LaravelEnvironmentImporterServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelEnvironmentImporterServiceProvider::class,
        ];
    }
}
