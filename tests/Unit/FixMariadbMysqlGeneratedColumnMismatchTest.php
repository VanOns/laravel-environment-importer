<?php

use VanOns\LaravelEnvironmentImporter\Processors\Database\FixMariadbMysqlGeneratedColumnMismatch;

it('applies when source is mariadb and target is mysql', function () {
    $processor = new FixMariadbMysqlGeneratedColumnMismatch('/tmp/dump.sql', [
        'source_db_type' => 'mariadb',
        'target_db_type' => 'mysql',
    ]);

    expect($processor->applies())->toBeTrue();
});

it('does not apply when source is not mariadb', function () {
    $processor = new FixMariadbMysqlGeneratedColumnMismatch('/tmp/dump.sql', [
        'source_db_type' => 'mysql',
        'target_db_type' => 'mysql',
    ]);

    expect($processor->applies())->toBeFalse();
});

it('does not apply when target is not mysql', function () {
    $processor = new FixMariadbMysqlGeneratedColumnMismatch('/tmp/dump.sql', [
        'source_db_type' => 'mariadb',
        'target_db_type' => 'mariadb',
    ]);

    expect($processor->applies())->toBeFalse();
});

it('does not apply when source and target db types are unknown', function () {
    $processor = new FixMariadbMysqlGeneratedColumnMismatch('/tmp/dump.sql');

    expect($processor->applies())->toBeFalse();
});

it('replaces a virtual generated column definition with a plain not null column', function () {
    $dumpFile = tempnam(sys_get_temp_dir(), 'dump_');
    file_put_contents($dumpFile, "  `full_name` varchar(510) GENERATED ALWAYS AS (concat(`first_name`,' ',`last_name`)) VIRTUAL,\n");

    $processor = new FixMariadbMysqlGeneratedColumnMismatch($dumpFile);
    $processor->process();

    expect(file_get_contents($dumpFile))->toBe("  `full_name` varchar(510) NOT NULL,\n");

    unlink($dumpFile);
});

it('replaces a stored generated column definition with a plain not null column', function () {
    $dumpFile = tempnam(sys_get_temp_dir(), 'dump_');
    file_put_contents($dumpFile, "  `total` decimal(10,2) GENERATED ALWAYS AS (`price` * `quantity`) STORED,\n");

    $processor = new FixMariadbMysqlGeneratedColumnMismatch($dumpFile);
    $processor->process();

    expect(file_get_contents($dumpFile))->toBe("  `total` decimal(10,2) NOT NULL,\n");

    unlink($dumpFile);
});

it('does not modify lines without a generated column definition', function () {
    $dumpFile = tempnam(sys_get_temp_dir(), 'dump_');
    $original = "  `name` varchar(255) NOT NULL,\n";
    file_put_contents($dumpFile, $original);

    $processor = new FixMariadbMysqlGeneratedColumnMismatch($dumpFile);
    $processor->process();

    expect(file_get_contents($dumpFile))->toBe($original);

    unlink($dumpFile);
});

it('fixes multiple generated column definitions in the same file', function () {
    $dumpFile = tempnam(sys_get_temp_dir(), 'dump_');
    file_put_contents($dumpFile, implode("\n", [
        '  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,',
        "  `full_name` varchar(510) GENERATED ALWAYS AS (concat(`first_name`,' ',`last_name`)) VIRTUAL,",
        '  `total` decimal(10,2) GENERATED ALWAYS AS (`price` * `quantity`) STORED,',
    ]) . "\n");

    $processor = new FixMariadbMysqlGeneratedColumnMismatch($dumpFile);
    $processor->process();

    $content = file_get_contents($dumpFile);
    expect(substr_count($content, 'NOT NULL'))->toBe(3);
    expect($content)->not->toContain('GENERATED ALWAYS');

    unlink($dumpFile);
});
