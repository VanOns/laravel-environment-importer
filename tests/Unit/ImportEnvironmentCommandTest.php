<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use VanOns\LaravelEnvironmentImporter\Commands\ImportEnvironmentCommand;
use VanOns\LaravelEnvironmentImporter\Exceptions\ImportEnvironmentException;
use VanOns\LaravelEnvironmentImporter\Processors\Database\DatabaseProcessor;

class AppliesTrueDatabaseProcessor extends DatabaseProcessor
{
    public static array $calls = [];

    public function process(): void
    {
        self::$calls[] = $this->options;
    }
}

class AppliesFalseDatabaseProcessor extends DatabaseProcessor
{
    public static array $calls = [];

    public function applies(): bool
    {
        return false;
    }

    public function process(): void
    {
        self::$calls[] = $this->options;
    }
}

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

function runImportSingleWithFakeRsync(ImportEnvironmentCommand $command, float $seconds = 0): array
{
    $bin = sys_get_temp_dir() . '/lei-fake-rsync-' . uniqid();
    mkdir($bin);
    $log = "{$bin}/call.log";

    file_put_contents("{$bin}/rsync", <<<SH
        #!/bin/sh
        printf 'SSHPASS=%s\\n' "\${SSHPASS-}" > '{$log}'
        printf '%s\\n' "\$@" >> '{$log}'
        sleep {$seconds}
        SH);
    chmod("{$bin}/rsync", 0755);

    // Symfony Process builds the child environment from $_ENV first.
    $env = $_ENV;
    $_ENV['PATH'] = $bin . PATH_SEPARATOR . getenv('PATH');

    try {
        callMethod($command, 'importSingle', 0, 'storage');
        $lines = file($log, FILE_IGNORE_NEW_LINES);
    } finally {
        $_ENV = $env;
        File::deleteDirectory($bin);
    }

    return [
        'args' => array_slice($lines, 1),
        'SSHPASS' => substr($lines[0], strlen('SSHPASS=')),
    ];
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

describe('sshCommand', function () {
    it('runs ssh through sshpass when ssh_password configured', function () {
        $command = makeCommand([], ['ssh_password' => 'hunter2']);

        expect(callMethod($command, 'sshCommand'))->toBe(['sshpass', '-e', 'ssh'])
            ->and(callMethod($command, 'sshEnv'))->toBe(['SSHPASS' => 'hunter2']);
    });

    it('passes the key with -i when ssh_key configured', function () {
        $command = makeCommand([], ['ssh_key' => '~/.ssh/id_rsa']);

        expect(callMethod($command, 'sshCommand'))->toBe(['ssh', '-i', '~/.ssh/id_rsa'])
            ->and(callMethod($command, 'sshEnv'))->toBe([]);
    });

    it('runs plain ssh when neither configured', function () {
        $command = makeCommand([], []);

        expect(callMethod($command, 'sshCommand'))->toBe(['ssh'])
            ->and(callMethod($command, 'sshEnv'))->toBe([]);
    });

    it('prefers ssh_password over ssh_key when both set', function () {
        $command = makeCommand([], ['ssh_password' => 'hunter2', 'ssh_key' => '~/.ssh/id_rsa']);

        expect(callMethod($command, 'sshCommand'))->toBe(['sshpass', '-e', 'ssh']);
    });
});

describe('importSingle', function () {
    it('hands ssh_password to sshpass instead of passing it to ssh as -p', function () {
        $command = makeCommandWithOutput(['db_timeout' => 60], array_merge(validEnvConfig(), ['ssh_password' => 'hunter2']));

        $call = runImportSingleWithFakeRsync($command);

        expect($call['args'])->toContain('sshpass -e ssh')
            ->and($call['args'])->not->toContain('hunter2')
            ->and(implode(' ', $call['args']))->not->toContain('-p hunter2')
            ->and($call['SSHPASS'])->toBe('hunter2');
    });

    it('passes ssh_key to ssh with -i', function () {
        $command = makeCommandWithOutput(['db_timeout' => 60], validEnvConfig());

        $call = runImportSingleWithFakeRsync($command);

        expect($call['args'])->toContain('ssh -i ~/.ssh/id_rsa')
            ->and($call['SSHPASS'])->toBe('');
    });

    it('does not stop rsync after db_timeout', function () {
        $command = makeCommandWithOutput(['db_timeout' => 0.5], validEnvConfig());

        $call = runImportSingleWithFakeRsync($command, seconds: 1);

        expect($call['args'])->toContain('deploy@example.com:/var/www/html/storage/');
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

    it('skips a processor when it does not apply', function () {
        AppliesFalseDatabaseProcessor::$calls = [];

        $command = makeCommandWithOutput(['database_processors' => [AppliesFalseDatabaseProcessor::class]]);
        callMethod($command, 'processDatabaseDump', '/tmp/dump.sql', 'mariadb', 'mysql');

        expect(AppliesFalseDatabaseProcessor::$calls)->toBe([]);
    });

    it('runs a processor when it applies', function () {
        AppliesTrueDatabaseProcessor::$calls = [];

        $command = makeCommandWithOutput(['database_processors' => [AppliesTrueDatabaseProcessor::class]]);
        callMethod($command, 'processDatabaseDump', '/tmp/dump.sql', 'mariadb', 'mysql');

        expect(AppliesTrueDatabaseProcessor::$calls)->toHaveCount(1);
    });

    it('passes the source and target db types to processor options', function () {
        AppliesTrueDatabaseProcessor::$calls = [];

        $command = makeCommandWithOutput(['database_processors' => [AppliesTrueDatabaseProcessor::class => ['opt' => true]]]);
        callMethod($command, 'processDatabaseDump', '/tmp/dump.sql', 'mariadb', 'mysql');

        expect(AppliesTrueDatabaseProcessor::$calls[0])->toBe([
            'opt' => true,
            'source_db_type' => 'mariadb',
            'target_db_type' => 'mysql',
        ]);
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
