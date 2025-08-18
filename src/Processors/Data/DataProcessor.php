<?php

namespace VanOns\LaravelEnvironmentImporter\Processors\Data;

abstract class DataProcessor
{
    /**
     * The table the processor is running for.
     */
    protected string $table;

    /**
     * Optional options for the processor.
     */
    protected array $options = [];

    public function __construct(string $table, array $options = [])
    {
        $this->table = $table;
        $this->options = $options;
    }

    /**
     * Define the tables the processor should run for.
     */
    abstract public function tables(): array;

    /**
     * Check if the processor applies to the current table.
     */
    public function applies(): bool
    {
        $tables = $this->tables();

        if (empty($tables)) {
            return false;
        }

        if (in_array('*', $tables)) {
            return true;
        }

        return in_array($this->table, $tables);
    }

    /**
     * Run the processor.
     */
    abstract public function process(): void;
}
