<?php

function featureValidEnvConfig(): array
{
    return [
        'ssh_host' => 'example.com',
        'ssh_username' => 'deploy',
        'ssh_key' => '~/.ssh/id_rsa',
        'ssh_password' => null,
        'ssh_base_path' => '/var/www/html',
        'db_type' => 'mysql',
        'db_host' => 'localhost',
        'db_name' => 'mydb',
        'db_username' => 'root',
        'db_password' => 'secret',
        'db_port' => '3306',
    ];
}

it('fails when no valid environments are configured', function () {
    config(['environment-importer.environments' => []]);

    $this->artisan('environment:import')
        ->assertFailed();
});

it('fails when environments have incomplete config', function () {
    config(['environment-importer.environments' => [
        'staging' => ['ssh_host' => 'example.com'],
    ]]);

    $this->artisan('environment:import')
        ->assertFailed();
});

it('fails when target environment does not exist in config', function () {
    config(['environment-importer.environments' => [
        'staging' => featureValidEnvConfig(),
    ]]);

    $this->artisan('environment:import', ['--target' => 'nonexistent'])
        ->assertFailed();
});

it('outputs error message when target environment does not exist', function () {
    config(['environment-importer.environments' => [
        'staging' => featureValidEnvConfig(),
    ]]);

    $this->artisan('environment:import', ['--target' => 'nonexistent'])
        ->expectsOutputToContain('"nonexistent" environment does not exist')
        ->assertFailed();
});

it('outputs error message when no environments found', function () {
    config(['environment-importer.environments' => []]);

    $this->artisan('environment:import')
        ->expectsOutputToContain('No environments found to import from')
        ->assertFailed();
});
