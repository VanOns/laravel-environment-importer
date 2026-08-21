<?php

namespace VanOns\LaravelEnvironmentImporter\Processors\Database;

class FixMariadbMysqlGeneratedColumnMismatch extends DatabaseProcessor
{
    /**
     * Only applies when importing a MariaDB dump into MySQL, since MariaDB's mysqldump
     * writes computed values for generated columns, which MySQL rejects.
     */
    public function applies(): bool
    {
        return ($this->options['source_db_type'] ?? null) === 'mariadb'
            && ($this->options['target_db_type'] ?? null) === 'mysql';
    }

    public function process(): void
    {
        $inputHandle = fopen($this->dumpFile, 'rb');
        $tempFile = $this->dumpFile . '.tmp';
        $outputHandle = fopen($tempFile, 'wb');

        while (($line = fgets($inputHandle)) !== false) {
            // MySQL doesn't support values for generated columns, which MariaDB does.
            // This is a workaround to make the dump compatible with MySQL by replacing the generated column definition with a regular NOT NULL column.
            // Source: https://dba.stackexchange.com/a/279248
            $fixedLine = preg_replace('/GENERATED ALWAYS AS\s*\(.*\)\s*(VIRTUAL|STORED)/i', 'NOT NULL', $line);
            fwrite($outputHandle, $fixedLine);
        }

        fclose($inputHandle);
        fclose($outputHandle);
        rename($tempFile, $this->dumpFile);
    }
}
