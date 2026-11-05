<?php

namespace App\Services\Konveksi;

use App\Audit\AuditLogger;
use App\Auth\LegacyUser;
use RuntimeException;

final class KonveksiHutangService
{
    public function __construct(
        private readonly KonveksiConnection $konveksi,
        private readonly AuditLogger $audit,
    ) {}

    public function createManual(LegacyUser $user, string $companyKey, array $p): array
    {
        $pegawai = trim((string) ($p['id_pegawai'] ?? ''));
        $nom = round((float) ($p['nominal'] ?? 0));
        if ($pegawai === '') {
            throw new RuntimeException('Pekerja/pegawai wajib dipilih.');
        }
        if ($nom <= 0) {
            throw new RuntimeException('Nominal hutang upah harus lebih dari Rp 0.');
        }

        $connection = $this->konveksi->connection();
        $tableMapping = $connection->table('system_table_map')->where('api_key', 'hutang_konveksi')->first();
        if (! $tableMapping) {
            throw new RuntimeException('Tabel hutang konveksi belum dipetakan.');
        }
        $table = (string) $tableMapping->table_name;

        $tgl = $p['tanggal'] ?? now('Asia/Jakarta')->format('Y-m-d');
        $idHutang = 'HTK-MAN-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        $now = now()->toIso8601String();
        $operator = (string) ($user->getUsername() ?: $user->getAuthIdentifier() ?: 'system');

        $r = [
            'id_hutang' => $idHutang,
            'tanggal' => $tgl,
            'id_pegawai' => $pegawai,
            'nama_pegawai' => $p['nama_pegawai'] ?? 'Pegawai',
            'divisi' => strtolower((string) ($p['divisi'] ?? 'jahit')),
            'id_spk' => 'MANUAL',
            'nomor_spk' => $p['nomor_referensi'] ?? 'MANUAL',
            'total_upah' => $nom,
            'kasbon_spk' => 0,
            'pembayaran_piutang' => 0,
            'hak_upah_bersih' => $nom,
            'nominal_dicairkan_awal' => 0,
            'nominal_disimpan' => $nom,
            'total_dibayar' => 0,
            'saldo_hutang' => $nom,
            'status' => 'Aktif',
            'idempotency_key' => 'HTK_MANUAL|'.$pegawai.'|'.$tgl.'|'.$nom.'|'.microtime(true),
            'catatan' => '['.($p['kategori'] ?? 'Upah Manual').'] '.($p['catatan'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
            'operator' => $operator,
        ];

        $connection->table($table)->insert([
            '_row_order' => 0,
            '_data_json' => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $this->audit->record(
            $user,
            'Konveksi',
            'CREATE_HUTANG_KONVEKSI',
            $idHutang,
            $r['nomor_spk'],
            null,
            $r,
            'Tambah hutang konveksi manual',
        );

        return [
            'status' => 'success',
            'code' => 'HUTANG_KONVEKSI_MANUAL_CREATED',
            'message' => 'Hutang Konveksi manual berhasil dicatat.',
            'data' => $r,
        ];
    }

    public function pay(LegacyUser $user, string $companyKey, array $p): array
    {
        $idp = trim((string) ($p['id_pegawai'] ?? ''));
        $nom = round((float) ($p['nominal_bayar'] ?? 0));
        $rek = trim((string) ($p['id_rekening'] ?? ''));
        if ($idp === '') {
            throw new RuntimeException('Pegawai belum dipilih.');
        }
        if ($nom <= 0) {
            throw new RuntimeException('Nominal penarikan harus lebih dari 0.');
        }
        if ($rek === '') {
            throw new RuntimeException('Rekening kas sumber wajib dipilih.');
        }

        $connection = $this->konveksi->connection();
        $tableHutang = (string) $connection->table('system_table_map')->where('api_key', 'hutang_konveksi')->value('table_name');
        $tableHist = (string) $connection->table('system_table_map')->where('api_key', 'riwayat_bayar_hutang_konveksi')->value('table_name');
        $tableKeu = (string) $connection->table('system_table_map')->where('api_key', 'keuangan')->value('table_name');

        if (! $tableHutang || ! $tableHist || ! $tableKeu) {
            throw new RuntimeException('Tabel keuangan/hutang konveksi belum lengkap dipetakan.');
        }

        $now = now()->toIso8601String();
        $tgl = $p['tanggal_bayar'] ?? now('Asia/Jakarta')->format('Y-m-d');
        $operator = (string) ($user->getUsername() ?: $user->getAuthIdentifier() ?: 'system');

        return $connection->transaction(function () use (
            $connection, $tableHutang, $tableHist, $tableKeu, $idp, $nom, $rek,
            $tgl, $now, $operator, $p, $user
        ): array {
            $hutangRows = $connection->table($tableHutang)->lockForUpdate()->get();
            $applicable = [];
            foreach ($hutangRows as $row) {
                $dec = json_decode((string) $row->_data_json, true);
                if (is_array($dec) && (string) ($dec['id_pegawai'] ?? '') === $idp && (float) ($dec['saldo_hutang'] ?? 0) > 0) {
                    $applicable[] = ['row_id' => $row->_row_id, 'data' => $dec];
                }
            }

            usort($applicable, fn ($a, $b) => strtotime((string) ($a['data']['tanggal'] ?? $a['data']['created_at'] ?? '1970-01-01')) <=> strtotime((string) ($b['data']['tanggal'] ?? $b['data']['created_at'] ?? '1970-01-01')));

            $total = 0;
            foreach ($applicable as $item) {
                $total += (float) $item['data']['saldo_hutang'];
            }

            if ($nom > $total) {
                throw new RuntimeException('Nominal pembayaran melebihi saldo hutang pegawai.');
            }

            $left = $nom;
            $generated = [];

            foreach ($applicable as $item) {
                if ($left <= 0) {
                    break;
                }
                $before = (float) $item['data']['saldo_hutang'];
                $pay = min($before, $left);
                $after = $before - $pay;

                $item['data']['total_dibayar'] = (float) ($item['data']['total_dibayar'] ?? 0) + $pay;
                $item['data']['saldo_hutang'] = $after;
                $item['data']['status'] = $after <= 0 ? 'Lunas' : 'Aktif';
                $item['data']['updated_at'] = $now;

                $connection->table($tableHutang)->where('_row_id', $item['row_id'])->update([
                    '_data_json' => json_encode($item['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);

                $payId = 'BYR-HTK-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
                $histRow = [
                    'id_pembayaran' => $payId,
                    'tanggal' => $tgl,
                    'id_hutang' => $item['data']['id_hutang'] ?? '',
                    'id_pegawai' => $idp,
                    'nama_pegawai' => $item['data']['nama_pegawai'] ?? ($p['nama_pegawai'] ?? 'Pegawai'),
                    'nominal_bayar' => $pay,
                    'saldo_sebelum' => $before,
                    'saldo_sesudah' => $after,
                    'id_rekening' => $rek,
                    'rekening_kas' => $p['rekening_kas'] ?? '',
                    'metode_pembayaran' => $p['metode_pembayaran'] ?? 'Tunai/Transfer',
                    'catatan' => $p['catatan'] ?? '',
                    'operator' => $operator,
                    'created_at' => $now,
                ];

                $connection->table($tableHist)->insert([
                    '_row_order' => 0,
                    '_data_json' => json_encode($histRow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);

                $generated[] = $histRow;
                $left -= $pay;
            }

            $journalId = 'K-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
            $journal = [
                'id' => $journalId,
                'tanggal' => $tgl,
                'tipe' => 'Keluar',
                'nominal' => $nom,
                'kategori' => 'Pembayaran Hutang Konveksi',
                'modul' => 'Hutang Konveksi',
                'id_rekening' => $rek,
                'id_referensi' => $generated[0]['id_pembayaran'] ?? '',
                'keterangan' => 'Pembayaran Hutang Konveksi '.($generated[0]['nama_pegawai'] ?? ''),
                'created_at' => $now,
                'created_by' => $operator,
            ];

            $connection->table($tableKeu)->insert([
                '_row_order' => 0,
                '_data_json' => json_encode($journal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $remaining = max(0, $total - $nom);
            $this->audit->record(
                $user,
                'Konveksi',
                'PAY_HUTANG_KONVEKSI',
                null,
                $idp,
                null,
                ['nominal' => $nom, 'sisa' => $remaining],
                'Pembayaran hutang upah konveksi',
            );

            return [
                'status' => 'success',
                'code' => 'HUTANG_KONVEKSI_PAID',
                'message' => 'Pembayaran Hutang Konveksi berhasil.',
                'data' => [
                    'riwayat' => $generated,
                    'sisa_saldo' => $remaining,
                    'jurnal' => $journal,
                ],
            ];
        });
    }
}
