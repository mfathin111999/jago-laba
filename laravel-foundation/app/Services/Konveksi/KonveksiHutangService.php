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
        private readonly KonveksiCollections $collections,
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

        if (! $this->collections->mapped('hutang_konveksi')) {
            throw new RuntimeException('Tabel hutang konveksi belum dipetakan.');
        }

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

        $this->collections->insert('hutang_konveksi', $r);

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

        if (! $this->collections->mapped('hutang_konveksi') || ! $this->collections->mapped('riwayat_bayar_hutang_konveksi') || ! $this->collections->mapped('keuangan')) {
            throw new RuntimeException('Tabel keuangan/hutang konveksi belum lengkap dipetakan.');
        }

        $now = now()->toIso8601String();
        $tgl = $p['tanggal_bayar'] ?? now('Asia/Jakarta')->format('Y-m-d');
        $operator = (string) ($user->getUsername() ?: $user->getAuthIdentifier() ?: 'system');

        return $this->konveksi->connection()->transaction(function () use ($idp, $nom, $rek, $tgl, $now, $operator, $p, $user): array {
            $applicable = [];
            foreach ($this->collections->rows('hutang_konveksi', true) as $row) {
                $dec = $row['data'];
                if ((string) ($dec['id_pegawai'] ?? '') === $idp && (float) ($dec['saldo_hutang'] ?? 0) > 0) {
                    $applicable[] = ['row_id' => $row['id'], 'data' => $dec];
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

                $this->collections->update('hutang_konveksi', $item['row_id'], $item['data']);

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

                $this->collections->insert('riwayat_bayar_hutang_konveksi', $histRow);

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

            $this->collections->insert('keuangan', $journal);

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
