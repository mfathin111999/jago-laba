<?php

namespace App\Services\Marketplace\Export;

/** Minimal OOXML workbook for the analytics report: inline text is never interpreted as a spreadsheet formula. */
final class AnalyticsXlsx
{
    private const KPI_LABELS = [
        'revenue' => 'Penjualan', 'fees' => 'Potongan bersih', 'net_income' => 'Untuk Dibayar', 'cogs' => 'HPP Produk',
        'gross_profit' => 'Laba setelah HPP', 'ads' => 'Iklan tercatat', 'operating' => 'Operasional tercatat',
        'net_profit' => 'Laba bersih operasional', 'known_operating_subtotal' => 'Subtotal operasional data tersedia',
        'orders' => 'Pesanan', 'missing_income' => 'Menunggu keuangan', 'missing_hpp' => 'HPP belum lengkap', 'unreconciled' => 'Perlu rekonsiliasi',
    ];

    /** @param array<string, mixed> $report */
    public static function bytes(array $report): string
    {
        $files = ['_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>'];
        $book = '';
        $rels = '';
        $types = '';
        $i = 0;
        foreach (self::sheets($report) as $name => $rows) {
            $i++;
            $book .= '<sheet name="'.self::xml($name).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
            $rels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
            $types .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $files['xl/worksheets/sheet'.$i.'.xml'] = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="40" customWidth="1"/><col min="2" max="12" width="24" customWidth="1"/></cols><sheetData>'.self::rows($rows).'</sheetData></worksheet>';
        }
        $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$book.'</sheets></workbook>';
        $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';
        $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$types.'</Types>';

        return self::zip($files);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, list<list<mixed>>>
     */
    private static function sheets(array $report): array
    {
        $mode = $report['mode'];
        $sheets = [
            'Metadata' => [
                ['Jago Laba — Analitik & Laporan'], ['Dari', $report['start']], ['Sampai', $report['end']], ['Dasar tanggal', $report['date_basis']],
                ['Kelompok', $mode], ['Mata uang', 'IDR'], ['Zona waktu', 'Asia/Jakarta'], ['Dihitung dari database', $report['generated_at']],
                ['Catatan', 'Settlement bukan bukti penarikan/bank. Biaya tercatat belum membuktikan cakupan lengkap.'],
                ['Rumus', 'Laba setelah HPP = Untuk Dibayar - HPP. Penyesuaian sumber tidak dihitung ulang.'],
            ],
            'KPI' => [['Metrik', 'Final + estimasi', 'Final']],
            'Harian' => [['Tanggal', 'Penjualan', 'Potongan', 'Untuk Dibayar', 'HPP Produk', 'Laba setelah HPP', 'Iklan', 'Operasional', 'Laba bersih', 'Pesanan']],
            'Biaya' => [['Komponen sumber', 'Jenis', 'Nominal']],
            'Produk' => [['Produk', 'Varian', 'SKU', 'Unit dipesan', 'Retur tercatat']],
        ];
        foreach ($report['stores'] as $store) {
            $sheets['Metadata'][] = ['Toko', $store['store_name'], $store['platform'], $store['id'], 'Sinkronisasi terakhir', $store['last_successful_sync_at'] ?? null];
        }
        foreach (self::KPI_LABELS as $key => $label) {
            $sheets['KPI'][] = [$label, $report['current']['all'][$key], $report['current']['settled'][$key]];
        }
        foreach ($report['current']['daily'] as $date => $day) {
            $r = $day[$mode];
            $sheets['Harian'][] = [$date, $r['revenue'], $r['fees'], $r['net_income'], $r['cogs'], $r['gross_profit'], $r['ads'], $r['operating'], $r['net_profit'], $r['orders']];
        }
        foreach ($report['current']['fees'][$mode] as $fee) {
            $sheets['Biaya'][] = [$fee['label'], $fee['effect'] < 0 ? 'Kredit' : 'Potongan', $fee['amount']];
        }
        foreach ($report['current']['products'] as $p) {
            $sheets['Produk'][] = [$p['name'], $p['variant'], $p['sku'], $p['quantity'], $p['returns_unknown'] ? null : $p['returns']];
        }

        return $sheets;
    }

    /** @param list<list<mixed>> $rows */
    private static function rows(array $rows): string
    {
        $data = '';
        foreach ($rows as $r => $cells) {
            $data .= '<row r="'.($r + 1).'">';
            foreach ($cells as $c => $value) {
                $column = '';
                $n = $c + 1;
                while ($n > 0) {
                    $n--;
                    $column = chr(65 + $n % 26).$column;
                    $n = intdiv($n, 26);
                }
                $ref = $column.($r + 1);
                $data .= is_int($value) || is_float($value)
                    ? '<c r="'.$ref.'"><v>'.$value.'</v></c>'
                    : '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.self::xml($value === null ? 'Belum tersedia' : (string) $value).'</t></is></c>';
            }
            $data .= '</row>';
        }

        return $data;
    }

    /** @param array<string, string> $files stored (not compressed) ZIP entries */
    private static function zip(array $files): string
    {
        $body = '';
        $directory = '';
        foreach ($files as $name => $content) {
            $offset = strlen($body);
            $size = strlen($content);
            $crc = crc32($content);
            $length = strlen($name);
            $body .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, 33, $crc, $size, $size, $length, 0).$name.$content;
            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 0, 0, 33, $crc, $size, $size, $length, 0, 0, 0, 0, 0, $offset).$name;
        }

        return $body.$directory.pack('VvvvvVVv', 0x06054B50, 0, 0, count($files), count($files), strlen($directory), strlen($body), 0);
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars(preg_replace('/[^\P{C}\t\n\r]/u', '', $value) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
