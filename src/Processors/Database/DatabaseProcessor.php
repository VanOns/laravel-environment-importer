<?php

namespace VanOns\LaravelEnvironmentImporter\Processors\Database;

abstract class DatabaseProcessor
{
    /**
     * The dump file the processor is running for.
     */
    protected string $dumpFile;

    /**
     * Optional options for the processor.
     */
    protected array $options = [];

    public function __construct(string $dumpFile, array $options = [])
    {
        $this->dumpFile = $dumpFile;
        $this->options = $options;
    }

    /**
     * Run the processor.
     */
    abstract public function process(): void;
}
