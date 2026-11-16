<?php

namespace App\Http\Controllers\Ops\Concerns;

use Symfony\Component\HttpFoundation\StreamedResponse;

trait StreamsCsv
{
    /**
     * Stream rows as a UTF-8 CSV (Excel-friendly BOM). Text cells starting with
     * = + - @ are prefixed with ' to neutralise spreadsheet formula injection.
     */
    protected function csv(string $filename, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $row));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
