<?php

use VanOns\LaravelEnvironmentImporter\Processors\Database\FixCommonMySQLErrors;

it('adds brackets around uuid() default value', function () {
    $dumpFile = tempnam(sys_get_temp_dir(), 'dump_');
    file_put_contents($dumpFile, "  `id` char(36) NOT NULL DEFAULT uuid(),\n");

    $processor = new FixCommonMySQLErrors($dumpFile);
    $processor->process();

    expect(file_get_contents($dumpFile))->toBe("  `id` char(36) NOT NULL DEFAULT (uuid()),\n");

    unlink($dumpFile);
});

it('does not modify lines without uuid() default value', function () {
    $dumpFile = tempnam(sys_get_temp_dir(), 'dump_');
    $original = "  `name` varchar(255) NOT NULL DEFAULT 'unknown',\n";
    file_put_contents($dumpFile, $original);

    $processor = new FixCommonMySQLErrors($dumpFile);
    $processor->process();

    expect(file_get_contents($dumpFile))->toBe($original);

    unlink($dumpFile);
});

it('fixes multiple uuid() default values in the same file', function () {
    $dumpFile = tempnam(sys_get_temp_dir(), 'dump_');
    file_put_contents($dumpFile, implode("\n", [
        '  `id` char(36) NOT NULL DEFAULT uuid(),',
        '  `name` varchar(255) NOT NULL,',
        '  `secondary_id` char(36) NOT NULL DEFAULT uuid(),',
    ]) . "\n");

    $processor = new FixCommonMySQLErrors($dumpFile);
    $processor->process();

    $content = file_get_contents($dumpFile);
    expect(substr_count($content, 'DEFAULT (uuid())'))->toBe(2);
    expect($content)->not->toContain('DEFAULT uuid()');

    unlink($dumpFile);
});
