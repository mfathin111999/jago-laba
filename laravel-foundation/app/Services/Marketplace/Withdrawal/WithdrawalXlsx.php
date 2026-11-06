<?php

namespace App\Services\Marketplace\Withdrawal;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Phar;
use PharData;
use RecursiveIteratorIterator;
use SimpleXMLElement;
use Throwable;

final class WithdrawalXlsx
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private static function xml(string $text): SimpleXMLElement
    {
        if (strlen($text) > 25000000 || preg_match('/<!DOCTYPE|<!ENTITY/i', $text)) {
            throw new InvalidArgumentException('XML Excel tidak aman atau terlalu besar.');
        }$old = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($text, SimpleXMLElement::class, LIBXML_NONET);
            if (! $xml) {
                throw new InvalidArgumentException('Struktur XLSX tidak valid.');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
    }

    private static function sheetRows(PharData $zip, string $path, array $strings): array
    {
        if (! isset($zip[$path]) || $zip[$path]->getSize() > 25000000) {
            throw new InvalidArgumentException('Sheet laporan tidak tersedia atau terlalu besar.');
        }$xml = self::xml($zip[$path]->getContent());
        $xml->registerXPathNamespace('m', self::NS);
        $rows = [];
        foreach ($xml->xpath('//m:sheetData/m:row') as $r) {
            if (count($rows) > 5000) {
                throw new InvalidArgumentException('Sheet penarikan terlalu panjang.');
            }$row = [];
            foreach ($r->children(self::NS)->c as $c) {
                $cellAttrs = $c->attributes();
                $coord = (string) $cellAttrs['r'];
                if (! preg_match('/^([A-Z]{1,3})\d+$/', $coord, $m)) {
                    continue;
                }$ch = $c->children(self::NS);
                $value = (string) $ch->v;
                if (isset($ch->f)) {
                    $row[$m[1]] = ['formula' => true];

                    continue;
                }if ((string) $cellAttrs['t'] === 's') {
                    if (! ctype_digit($value) || ! array_key_exists((int) $value, $strings)) {
                        throw new InvalidArgumentException('Referensi teks Excel tidak valid.');
                    }$value = $strings[(int) $value];
                } elseif ((string) $cellAttrs['t'] === 'inlineStr') {
                    $ch->is->registerXPathNamespace('m', self::NS);
                    $value = implode('', array_map('strval', $ch->is->xpath('.//m:t')));
                } elseif ((string) $cellAttrs['t'] === 'e') {
                    throw new InvalidArgumentException('Sheet mengandung error Excel.');
                }$row[$m[1]] = $value;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private static function sourceDate(string $value, string $timezone): array
    {
        $value = trim($value);
        if ($value === '' || $value === '/') {
            return ['', null];
        }
        if (is_numeric($value)) {
            if ((float) $value < 1 || (float) $value > 100000) {
                throw new InvalidArgumentException('Tanggal Excel di luar rentang.');
            }$seconds = (int) round((float) $value * 86400);
            $value = (new DateTimeImmutable('1899-12-30', new DateTimeZone($timezone)))->modify('+'.$seconds.' seconds')->format(fmod((float) $value, 1.0) == 0.0 ? 'Y-m-d' : 'Y-m-d H:i:s');
        }
        foreach (['Y/m/d', 'Y-m-d', 'Y/m/d H:i:s', 'Y-m-d H:i:s', 'Y/m/d H:i', 'Y-m-d H:i'] as $format) {
            $d = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone($timezone));
            if ($d && $d->format($format) === $value) {
                $precision = str_contains($format, 'H') ? 'datetime' : 'date';

                return [$precision === 'date' ? $d->format('Y-m-d') : $d->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s'), $precision];
            }
        }
        throw new InvalidArgumentException('Format tanggal penarikan tidak dikenali.');
    }

    public static function parse(string $path): array
    {
        if (! is_file($path) || filesize($path) > 10485760) {
            throw new InvalidArgumentException('Maksimal XLSX 10 MB.');
        }try {
            $zip = new PharData($path, 0, null, Phar::ZIP);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Berkas bukan XLSX yang valid atau masih terenkripsi.');
        }
        $total = 0;
        $entries = 0;
        foreach (new RecursiveIteratorIterator($zip) as $entry) {
            $total += $entry->getSize();
            if (++$entries > 2000 || $total > 80000000) {
                throw new InvalidArgumentException('Isi XLSX terlalu besar.');
            }
        }if (isset($zip['xl/vbaProject.bin'])) {
            throw new InvalidArgumentException('Berkas macro tidak didukung.');
        }
        foreach (['xl/workbook.xml', 'xl/_rels/workbook.xml.rels'] as $f) {
            if (! isset($zip[$f]) || $zip[$f]->getSize() > 1000000) {
                throw new InvalidArgumentException('Metadata XLSX tidak valid.');
            }
        }
        $book = self::xml($zip['xl/workbook.xml']->getContent());
        $book->registerXPathNamespace('m', self::NS);
        $rels = self::xml($zip['xl/_rels/workbook.xml.rels']->getContent());
        $paths = [];
        foreach ($rels->children('http://schemas.openxmlformats.org/package/2006/relationships') as $rel) {
            $relAttrs = $rel->attributes();
            if ((string) $relAttrs['TargetMode'] === 'External') {
                continue;
            }$target = ltrim((string) $relAttrs['Target'], '/');
            if (str_starts_with($target, 'worksheets/')) {
                $target = 'xl/'.$target;
            }if (preg_match('~^xl/worksheets/[A-Za-z0-9_.-]+\.xml$~', $target)) {
                $paths[(string) $relAttrs['Id']] = $target;
            }
        }
        $sheets = [];
        foreach ($book->xpath('//m:sheets/m:sheet') as $sheet) {
            $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            if (isset($paths[(string) $attrs['id']])) {
                $sheets[(string) $sheet['name']] = $paths[(string) $attrs['id']];
            }
        }
        if (! isset($sheets['Riwayat penarikan'],$sheets['Laporan'])) {
            throw new InvalidArgumentException('Gunakan laporan income TikTok asli dengan sheet Riwayat penarikan dan Laporan.');
        }
        $strings = [];
        if (isset($zip['xl/sharedStrings.xml'])) {
            if ($zip['xl/sharedStrings.xml']->getSize() > 25000000) {
                throw new InvalidArgumentException('Teks XLSX terlalu besar.');
            }$ss = self::xml($zip['xl/sharedStrings.xml']->getContent());
            $ss->registerXPathNamespace('m', self::NS);
            foreach ($ss->xpath('//m:si') as $si) {
                $si->registerXPathNamespace('m', self::NS);
                $strings[] = implode('', array_map('strval', $si->xpath('.//m:t')));
            }
        }
        $meta = self::sheetRows($zip, $sheets['Laporan'], $strings);
        $currency = '';
        $zone = '';
        foreach ($meta as $r) {
            $vals = array_values(array_filter($r, 'is_string'));
            if (in_array('Mata uang', $vals, true)) {
                $currency = trim((string) end($vals));
            }if (in_array('Zona waktu', $vals, true)) {
                $zone = trim((string) end($vals));
            }
        }if (! preg_match('/^[A-Z]{3}$/', $currency) || ! preg_match('/^UTC([+-])(\d{1,2})(?::(\d{2}))?$/', $zone, $tz) || (int) $tz[2] > 14) {
            throw new InvalidArgumentException('Mata uang atau zona waktu laporan tidak dikenali.');
        }$timezone = $tz[1].str_pad($tz[2], 2, '0', STR_PAD_LEFT).':'.($tz[3] ?? '00');
        $sheet = self::sheetRows($zip, $sheets['Riwayat penarikan'], $strings);
        $header = array_shift($sheet);
        $columns = [];
        foreach ($header as $col => $label) {
            if (is_string($label)) {
                $columns[trim($label)] = $col;
            }
        }
        foreach (['Jenis transaksi', 'ID referensi', 'Waktu permintaan', 'Total', 'Status', 'Waktu keberhasilan', 'Rekening bank'] as $label) {
            if (! isset($columns[$label])) {
                throw new InvalidArgumentException('Kolom laporan berubah: '.$label);
            }
        }
        $f = fopen('php://temp', 'w+');
        fputcsv($f, ['withdrawal_id', 'requested_at', 'completed_at', 'amount', 'fee', 'net_amount', 'currency', 'status', 'type', 'account_number'], ',', '"', '');
        $extra = [];
        $skipped = [];
        foreach ($sheet as $i => $r) {
            $get = static function ($label) use ($r, $columns) {
                $v = $r[$columns[$label]] ?? '';
                if (! is_string($v)) {
                    throw new InvalidArgumentException('Formula pada kolom penarikan tidak didukung.');
                }

                return trim($v);
            };
            $type = $get('Jenis transaksi');
            if ($type === '') {
                continue;
            }if (strcasecmp($type, 'Withdrawal') !== 0) {
                $skipped[$type] = ($skipped[$type] ?? 0) + 1;

                continue;
            }
            $id = $get('ID referensi');
            $signed = $get('Total');
            if (! preg_match('/^-\d{1,15}(?:\.\d{1,2})?$/', $signed)) {
                throw new InvalidArgumentException('Total Withdrawal harus menunjukkan pengurangan saldo (negatif), baris '.($i + 2));
            }[$requested,$precision] = self::sourceDate($get('Waktu permintaan'), $timezone);
            [$completed,$completedPrecision] = self::sourceDate($get('Waktu keberhasilan'), $timezone);
            $originalStatus = $get('Status');
            $status = $originalStatus === 'Transferred' ? 'SUCCESS' : $originalStatus;
            fputcsv($f, [$id, $requested, $completed, substr($signed, 1), '', '', $currency, $status, $type, $get('Rekening bank')], ',', '"', '');
            $extra[$id] = ['source_sheet' => 'Riwayat penarikan', 'source_timezone' => $zone, 'source_date_precision' => $precision, 'completed_date_precision' => $completedPrecision, 'source_signed_amount' => $signed, 'source_status' => $originalStatus];
        }
        rewind($f);
        $rows = WithdrawalService::parseCsv(stream_get_contents($f));
        fclose($f);
        foreach ($rows as &$row) {
            $row = array_merge($row, $extra[$row['external_id']]);
            unset($row['row_hash']);
            $row['row_hash'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
        }unset($row);

        return ['rows' => $rows, 'meta' => ['sheet' => 'Riwayat penarikan', 'currency' => $currency, 'timezone' => $zone, 'skipped' => $skipped, 'notice' => 'Hanya Withdrawal. Total negatif saldo ditampilkan positif sebagai nominal penarikan. Biaya dan bersih dikirim tidak tersedia dalam laporan ini. Tanggal tanpa jam dipertahankan sebagai tanggal sumber; waktu lengkap dikonversi ke WIB.']];
    }
}
