<?php

namespace VanOns\LaravelEnvironmentImporter\Processors\Database;

class FixCommonMySQLErrors extends DatabaseProcessor
{
    public function process(): void
    {
        $this->addBracketsToUuidDefaultValue();
    }

    protected function addBracketsToUuidDefaultValue(): void
    {
        $inputHandle = fopen($this->dumpFile, 'rb');
        $tempFile = $this->dumpFile . '.tmp';
        $outputHandle = fopen($tempFile, 'wb');

        while (($line = fgets($inputHandle)) !== false) {
            // mysqldump writes without brackets, but when importing the dump, it breaks. Adding brackets around the
            // uuid() default value ensures compatibility.
            $fixedLine = str_replace('DEFAULT uuid()', 'DEFAULT (uuid())', $line);
            fwrite($outputHandle, $fixedLine);
        }

        fclose($inputHandle);
        fclose($outputHandle);
        rename($tempFile, $this->dumpFile);
    }
}
