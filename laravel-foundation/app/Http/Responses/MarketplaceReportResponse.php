<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Inertia\Inertia;

final class MarketplaceReportResponse
{
    public static function make(Request $request, string $title, array $data, string $rowKey, array $columns): mixed
    {
        $rows = $data[$rowKey] ?? [];
        if ($request->input('format') === 'csv') {
            return CsvDownload::stream(
                'laporan-marketplace.csv',
                array_values($columns),
                (static function () use ($rows, $columns): \Generator {
                    foreach ($rows as $row) {
                        yield array_map(static fn (string $key): mixed => $row[$key] ?? '', array_keys($columns));
                    }
                })(),
            );
        }
        $props = ['title' => $title, 'data' => $data, 'rows' => $rows, 'columns' => $columns, 'filters' => $request->validated(), 'read_only' => true];
        if ($request->expectsJson() || $request->input('format') === 'json') {
            return response()->json($props)->header('Cache-Control', 'no-store, private');
        }

        return Inertia::render('Marketplace/ReadReport', $props);
    }
}
