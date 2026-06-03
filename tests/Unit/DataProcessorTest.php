<?php

use VanOns\LaravelEnvironmentImporter\Processors\Data\DataProcessor;

class ConcreteDataProcessor extends DataProcessor
{
    public function __construct(string $table, array $options = [], private array $tablesToReturn = [])
    {
        parent::__construct($table, $options);
    }

    public function tables(): array
    {
        return $this->tablesToReturn;
    }

    public function process(): void
    {
    }
}

it('returns false when tables list is empty', function () {
    $processor = new ConcreteDataProcessor('users', [], []);
    expect($processor->applies())->toBeFalse();
});

it('returns true when wildcard is in tables list', function () {
    $processor = new ConcreteDataProcessor('users', [], ['*']);
    expect($processor->applies())->toBeTrue();
});

it('returns true when table is in tables list', function () {
    $processor = new ConcreteDataProcessor('users', [], ['users', 'orders']);
    expect($processor->applies())->toBeTrue();
});

it('returns false when table is not in tables list', function () {
    $processor = new ConcreteDataProcessor('orders', [], ['users']);
    expect($processor->applies())->toBeFalse();
});
