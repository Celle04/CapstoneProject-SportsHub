<?php

namespace App\Http\Controllers\Concerns;

/**
 * Neutralises spreadsheet formula injection in downloaded CSV files.
 *
 * A cell whose value starts with =, +, -, @, a tab or a newline is treated as
 * a formula by Excel and other spreadsheet applications. Prefixing it with a
 * tab makes the application show the value as literal text instead of running
 * it, so data a user typed (a name, a remark) can never execute on the machine
 * of the coordinator who opens the download.
 */
trait SanitizesCsvCells
{
    /**
     * @param  string  $value
     * @return string
     */
    protected function csvCell(string $value): string
    {
        if (preg_match('#^[=+\-@\t\r\n]#', $value)) {
            return "\t".$value;
        }

        return $value;
    }
}
