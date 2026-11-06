<?php

namespace App\Services\Marketplace\Export;

/** Writes the global analytics report as CSV (UTF-8 with BOM). Cells that start like a formula are neutralised. */
final class AnalyticsCsv
{
    /**
     * @param  resource  $out
     * @param  array<string, mixed>  $report
     */
    public static function write($out, array $report): void
    {
        fwrite($out, "\xEF\xBB\xBF");
        $write = static function (array $row) use ($out): void {
            $row = array_map(
                static fn ($cell) => $cell === null ? 'Menunggu data' : (is_string($cell) && preg_match('/^[=+@\-\t\r\n]/', $cell) ? "'".$cell : $cell),
                $row,
            );
            fputcsv($out, $row, ',', '"', '');
        };

        $write(['Analitik Global', $report['start'], $report['end'], $report['date_basis'], $report['mode']]);
        $write(['Dibuat (WIB)', $report['generated_at']]);
        foreach (['all' => 'Sudah + Belum Cair', 'settled' => 'Sudah Cair'] as $bucket => $title) {
            $write([$title]);
            foreach ($report['current'][$bucket] as $key => $value) {
                $write([$key, $value]);
            }
        }

        $mode = $report['mode'];
        $write(['Proyek', 'Platform', 'Omzet', 'Potongan', 'Pendapatan Setelah Potongan', 'HPP', 'Laba Kotor', 'Pengeluaran', 'Penyesuaian', 'Laba Bersih', 'Pesanan']);
        foreach ($report['current']['by_store'] as $project) {
            $r = $project[$mode];
            $write([$project['store']['store_name'], $project['store']['platform'], $r['revenue'], $r['fees'], $r['net_income'], $r['cogs'], $r['gross_profit'], $r['expenses'], $r['adjustments'], $r['net_profit'], $r['orders']]);
        }

        $write(['Tanggal', 'Omzet', 'Laba Bersih', 'Pengeluaran', 'Retur', 'Pesanan']);
        foreach ($report['current']['daily'] as $date => $day) {
            $r = $day[$mode];
            $write([$date, $r['revenue'], $r['net_profit'], $r['expenses'], $day['all']['returns'], $r['orders']]);
        }

        $write(['Rincian Biaya', 'Jenis', 'Jumlah']);
        foreach ($report['current']['fees'][$mode] as $fee) {
            $write([$fee['label'], $fee['effect'] < 0 ? 'Kredit' : 'Potongan', $fee['amount']]);
        }
    }
}
