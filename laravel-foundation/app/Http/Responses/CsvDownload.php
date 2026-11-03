<?php

namespace App\Http\Responses;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * UTF-8 CSV download with spreadsheet formula-injection protection.
 */
final class CsvDownload
{
    /** Prefixes text that a spreadsheet would execute as a formula. */
    public static function safe(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+@\-\t\r\n]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<list<mixed>>  $rows
     */
    public static function stream(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(self::safe(...), $row), ',', '"', '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'no-store, private']);
    }
}
