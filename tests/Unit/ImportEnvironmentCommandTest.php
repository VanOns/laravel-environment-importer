<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use VanOns\LaravelEnvironmentImporter\Commands\ImportEnvironmentCommand;
use VanOns\LaravelEnvironmentImporter\Exceptions\ImportEnvironmentException;

function makeCommand(array $config = [], array $envConfig = []): ImportEnvironmentCommand
{
    $command = new ImportEnvironmentCommand();

    (new ReflectionProperty($command, 'config'))->setValue($command, $config);
    (new ReflectionProperty($command, 'environmentConfig'))->setValue($command, $envConfig);

    return $command;
}

function makeCommandWithOutput(array $config = [], array $envConfig = []): ImportEnvironmentCommand
{
    $command = makeCommand($config, $envConfig);

    $output = new OutputStyle(new ArrayInput([]), new NullOutput());
    (new ReflectionProperty($command, 'output'))->setValue($command, $output);

    return $command;
}

function callMethod(object $object, string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod($object, $method))->invoke($object, ...$args);
}

function validEnvConfig(): array
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

describe('getEnvironments', function () {
    it('returns environment when config is complete', function () {
        $command = makeCommand(['environments' => ['staging' => validEnvConfig()]]);

        expect(callMethod($command, 'getEnvironments'))->toBe(['staging']);
    });

    it('excludes environment missing a required key', function () {
        $config = validEnvConfig();
        unset($config['ssh_host']);

        $command = makeCommand(['environments' => ['staging' => $config]]);

        expect(callMethod($command, 'getEnvironments'))->toBe([]);
    });

    it('excludes environment with required key set to empty string', function () {
        $config = validEnvConfig();
        $config['db_name'] = '';

        $command = makeCommand(['environments' => ['staging' => $config]]);

        expect(callMethod($command, 'getEnvironments'))->toBe([]);
    });

    it('excludes environment with neither ssh_key nor ssh_password', function () {
        $config = array_merge(validEnvConfig(), ['ssh_key' => null, 'ssh_password' => null]);

        $command = makeCommand(['environments' => ['staging' => $config]]);

        expect(callMethod($command, 'getEnvironments'))->toBe([]);
    });

    it('includes environment with ssh_password and no ssh_key', function () {
        $config = array_merge(validEnvConfig(), ['ssh_key' => null, 'ssh_password' => 'hunter2']);

        $command = makeCommand(['environments' => ['staging' => $config]]);

        expect(callMethod($command, 'getEnvironments'))->toBe(['staging']);
    });

    it('returns only valid environments when some are incomplete', function () {
        $broken = array_merge(validEnvConfig(), ['ssh_host' => null]);

        $command = makeCommand(['environments' => [
            'staging' => validEnvConfig(),
            'broken' => $broken,
        ]]);

        expect(callMethod($command, 'getEnvironments'))->toBe(['staging']);
    });

    it('returns empty array when environments config is missing', function () {
        $command = makeCommand([]);

        expect(callMethod($command, 'getEnvironments'))->toBe([]);
    });
});

describe('sshAuth', function () {
    it('returns -p flag when ssh_password configured', function () {
        $command = makeCommand([], ['ssh_password' => 'hunter2']);

        expect(callMethod($command, 'sshAuth'))->toBe('-p hunter2');
    });

    it('returns -i flag when ssh_key configured', function () {
        $command = makeCommand([], ['ssh_key' => '~/.ssh/id_rsa']);

        expect(callMethod($command, 'sshAuth'))->toBe('-i ~/.ssh/id_rsa');
    });

    it('returns empty string when neither configured', function () {
        $command = makeCommand([], []);

        expect(callMethod($command, 'sshAuth'))->toBe('');
    });

    it('prefers ssh_password over ssh_key when both set', function () {
        $command = makeCommand([], ['ssh_password' => 'hunter2', 'ssh_key' => '~/.ssh/id_rsa']);

        expect(callMethod($command, 'sshAuth'))->toBe('-p hunter2');
    });
});

describe('dbUseSsh', function () {
    it('returns false by default', function () {
        expect(callMethod(makeCommand([], []), 'dbUseSsh'))->toBeFalse();
    });

    it('returns true when configured', function () {
        expect(callMethod(makeCommand([], ['db_use_ssh' => true]), 'dbUseSsh'))->toBeTrue();
    });
});

describe('dbSshTunnelPort', function () {
    it('returns default 3307', function () {
        expect(callMethod(makeCommand([], []), 'dbSshTunnelPort'))->toBe('3307');
    });

    it('returns configured port', function () {
        expect(callMethod(makeCommand([], ['db_ssh_tunnel_port' => '13307']), 'dbSshTunnelPort'))->toBe('13307');
    });
});

describe('dbPort', function () {
    it('returns default 3306', function () {
        expect(callMethod(makeCommand([], []), 'dbPort'))->toBe('3306');
    });

    it('returns configured port', function () {
        expect(callMethod(makeCommand([], ['db_port' => '5432']), 'dbPort'))->toBe('5432');
    });
});

describe('getDatabaseDumpClient', function () {
    it('throws for unknown db_no_lock_strategy value', function () {
        $command = makeCommand(
            ['db_no_lock_strategy' => 'undefined_strategy', 'db_dump_binary_path' => '/usr/bin'],
            array_merge(validEnvConfig(), ['db_type' => 'mysql']),
        );

        expect(fn () => callMethod($command, 'getDatabaseDumpClient', false))
            ->toThrow(ImportEnvironmentException::class, 'Unknown db_no_lock_strategy value "undefined_strategy"');
    });

    it('accepts single_transaction lock strategy', function () {
        $command = makeCommand(
            ['db_no_lock_strategy' => 'single_transaction', 'db_dump_binary_path' => '/usr/bin'],
            array_merge(validEnvConfig(), ['db_type' => 'mysql']),
        );

        expect(fn () => callMethod($command, 'getDatabaseDumpClient', false))
            ->not->toThrow(ImportEnvironmentException::class);
    });

    it('accepts skip_lock_tables lock strategy', function () {
        $command = makeCommand(
            ['db_no_lock_strategy' => 'skip_lock_tables', 'db_dump_binary_path' => '/usr/bin'],
            array_merge(validEnvConfig(), ['db_type' => 'mysql']),
        );

        expect(fn () => callMethod($command, 'getDatabaseDumpClient', false))
            ->not->toThrow(ImportEnvironmentException::class);
    });

    it('skips lock strategy validation for pgsql databases', function () {
        $command = makeCommand(
            ['db_no_lock_strategy' => 'undefined_strategy', 'db_dump_binary_path' => '/usr/bin'],
            array_merge(validEnvConfig(), ['db_type' => 'pgsql']),
        );

        expect(fn () => callMethod($command, 'getDatabaseDumpClient', false))
            ->not->toThrow(ImportEnvironmentException::class);
    });

    it('skips lock strategy validation for mariadb local dumps', function () {
        $command = makeCommand(
            ['db_no_lock_strategy' => 'undefined_strategy', 'db_dump_binary_path' => '/usr/bin'],
            array_merge(validEnvConfig(), ['db_type' => 'mariadb']),
        );

        // local=true skips the lock strategy check
        expect(fn () => callMethod($command, 'getDatabaseDumpClient', true))
            ->not->toThrow(ImportEnvironmentException::class);
    });
});

describe('processDatabaseDump', function () {
    it('returns early when no processors configured', function () {
        $command = makeCommandWithOutput(['database_processors' => []]);

        callMethod($command, 'processDatabaseDump', '/tmp/dump.sql');

        expect(true)->toBeTrue();
    });

    it('throws when processor class does not extend DatabaseProcessor', function () {
        $command = makeCommandWithOutput(['database_processors' => [stdClass::class]]);

        expect(fn () => callMethod($command, 'processDatabaseDump', '/tmp/dump.sql'))
            ->toThrow(ImportEnvironmentException::class, 'must extend');
    });

    it('throws when keyed processor class does not extend DatabaseProcessor', function () {
        $command = makeCommandWithOutput(['database_processors' => [stdClass::class => ['opt' => true]]]);

        expect(fn () => callMethod($command, 'processDatabaseDump', '/tmp/dump.sql'))
            ->toThrow(ImportEnvironmentException::class, 'must extend');
    });
});

describe('processDatabaseData', function () {
    it('returns early when no processors configured', function () {
        $command = makeCommandWithOutput(['data_processors' => []]);

        callMethod($command, 'processDatabaseData');

        expect(true)->toBeTrue();
    });

    it('throws when processor class does not extend DataProcessor', function () {
        Schema::shouldReceive('getTables')->andReturn([['name' => 'users']]);

        $command = makeCommandWithOutput(['data_processors' => [stdClass::class]]);

        expect(fn () => callMethod($command, 'processDatabaseData'))
            ->toThrow(ImportEnvironmentException::class, 'must extend');
    });
});
