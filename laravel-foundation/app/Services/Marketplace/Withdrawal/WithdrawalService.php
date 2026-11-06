<?php

namespace App\Services\Marketplace\Withdrawal;

use App\Services\Marketplace\MarketplaceScope;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use PDO;
use Throwable;

final class WithdrawalService
{
    public function __construct(private readonly DatabaseManager $database, private readonly MarketplaceScope $scope) {}

    private static function money(string $v, bool $nullable = false): ?string
    {
        $v = trim($v);
        if ($nullable && $v === '') {
            return null;
        }if (! preg_match('/^\d{1,15}(?:\.\d{1,2})?$/', $v)) {
            throw new InvalidArgumentException('Nominal harus angka tanpa pemisah ribuan, desimal memakai titik.');
        }$parts = explode('.', $v);

        return ltrim($parts[0], '0').(ltrim($parts[0], '0') === '' ? '0' : '').'.'.str_pad($parts[1] ?? '', 2, '0');
    }

    private static function date(string $v, bool $nullable = false): ?string
    {
        if ($nullable && trim($v) === '') {
            return null;
        }foreach (['Y-m-d H:i:s', 'Y-m-d'] as $f) {
            $d = DateTimeImmutable::createFromFormat('!'.$f, trim($v), new DateTimeZone('Asia/Jakarta'));
            if ($d && $d->format($f) === trim($v)) {
                return $d->format('Y-m-d H:i:s');
            }
        }throw new InvalidArgumentException('Tanggal harus YYYY-MM-DD atau YYYY-MM-DD HH:MM:SS (WIB).');
    }

    public static function parseCsv(string $csv): array
    {
        if (strlen($csv) > 2097152) {
            throw new InvalidArgumentException('Maksimal berkas 2 MB.');
        }$csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        if (str_contains($csv, "\0")) {
            throw new InvalidArgumentException('Gunakan CSV UTF-8, bukan berkas Excel biner.');
        }$f = fopen('php://temp', 'w+');
        fwrite($f, $csv);
        rewind($f);
        $first = strtok($csv, "\n") ?: '';
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $headers = fgetcsv($f, 0, $sep, '"', '');
        if (! $headers) {
            throw new InvalidArgumentException('CSV kosong.');
        }$headers = array_map(static fn ($s) => strtolower(trim($s)), $headers);
        $required = ['withdrawal_id', 'requested_at', 'amount', 'currency', 'status'];
        foreach ($required as $h) {
            if (! in_array($h, $headers, true)) {
                throw new InvalidArgumentException('Kolom wajib tidak ada: '.$h.'. Gunakan format template.');
            }
        }if (count($headers) !== count(array_unique($headers))) {
            throw new InvalidArgumentException('Nama kolom berulang.');
        }$rows = [];
        $line = 1;
        while (($values = fgetcsv($f, 0, $sep, '"', '')) !== false) {
            $line++;
            if (count($values) === 1 && trim((string) $values[0]) === '') {
                continue;
            }if (count($rows) >= 2000) {
                throw new InvalidArgumentException('Maksimal 2.000 baris per impor.');
            }if (count($values) !== count($headers)) {
                throw new InvalidArgumentException('Jumlah kolom tidak sesuai pada baris '.$line);
            }$r = array_combine($headers, $values);
            try {
                $id = trim($r['withdrawal_id']);
                if (! preg_match('/^[A-Za-z0-9._:\/-]{1,120}$/', $id)) {
                    throw new InvalidArgumentException('ID penarikan tidak valid.');
                }if (isset($rows[$id])) {
                    throw new InvalidArgumentException('ID penarikan berulang dalam berkas.');
                }$status = trim($r['status']);
                if ($status === '' || mb_strlen($status) > 100) {
                    throw new InvalidArgumentException('Status wajib diisi, maksimal 100 karakter.');
                }$normalized = match (strtoupper($status)) {
                    'PAID','COMPLETED','SUCCESS','SUCCESSFUL','BERHASIL','SELESAI' => 'succeeded','PROCESSING','PENDING','IN_PROGRESS','DIPROSES','MENUNGGU' => 'processing','FAILED','REJECTED','GAGAL','DITOLAK' => 'failed','CANCELLED','CANCELED','DIBATALKAN' => 'cancelled',default => 'unknown'
                };
                $currency = strtoupper(trim($r['currency']));
                if (! preg_match('/^[A-Z]{3}$/', $currency)) {
                    throw new InvalidArgumentException('Mata uang tidak valid.');
                }$account = preg_replace('/\D/', '', $r['account_number'] ?? '');
                $row = ['external_id' => $id, 'withdrawal_type' => mb_substr(trim($r['type'] ?? ''), 0, 30) ?: null, 'requested_at' => self::date($r['requested_at']), 'completed_at' => self::date($r['completed_at'] ?? '', true), 'amount' => self::money($r['amount']), 'fee' => self::money($r['fee'] ?? '', true), 'net_amount' => self::money($r['net_amount'] ?? '', true), 'currency' => $currency, 'source_status' => $status, 'normalized_status' => $normalized, 'bank_name' => mb_substr(trim($r['bank_name'] ?? ''), 0, 100) ?: null, 'account_mask' => $account !== '' ? '•••• '.substr($account, -4) : null, 'failure_reason' => mb_substr(trim($r['failure_reason'] ?? ''), 0, 500) ?: null];
                if ($row['completed_at'] !== null && $row['completed_at'] < $row['requested_at']) {
                    throw new InvalidArgumentException('Tanggal selesai mendahului pengajuan.');
                }$row['row_hash'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
                $rows[$id] = $row;
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException('Baris '.$line.': '.$e->getMessage());
            }
        }
        fclose($f);
        if (! $rows) {
            throw new InvalidArgumentException('Tidak ada data penarikan.');
        }

return array_values($rows);
    }

    public function import(string $store, array $rows, string $hash): array
    {
        $scope = $this->scope;
        $scope->assertStoreAccess($store);
        $company = $scope->companyKey();
        $actor = (string) ($this->scope->userId());
        $counts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        $this->pdo()->beginTransaction();
        try {
            $lock = $this->pdo()->prepare('SELECT id FROM marketplace_stores WHERE id=? AND company_key=? FOR UPDATE');
            $lock->execute([$store, $company]);
            foreach ($rows as $r) {
                $q = $this->pdo()->prepare('SELECT id,row_hash FROM marketplace_withdrawals WHERE company_key=? AND store_id=? AND external_id=? FOR UPDATE');
                $q->execute([$company, $store, $r['external_id']]);
                $old = $q->fetch(PDO::FETCH_ASSOC);
                if ($old && $old['row_hash'] === $r['row_hash']) {
                    $counts['unchanged']++;

                    continue;
                }
                $data = $r + ['company_key' => $company, 'store_id' => $store, 'source_kind' => 'import', 'source_hash' => $hash, 'imported_by' => $actor, 'imported_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
                $cols = array_keys($data);
                $sql = 'INSERT INTO marketplace_withdrawals ('.implode(',', $cols).') VALUES ('.implode(',', array_fill(0, count($cols), '?')).') ON DUPLICATE KEY UPDATE '.implode(',', array_map(static fn ($c) => $c.'=VALUES('.$c.')', array_diff($cols, ['company_key', 'store_id', 'external_id'])));
                $this->pdo()->prepare($sql)->execute(array_values($data));
                $this->pdo()->prepare('INSERT INTO marketplace_withdrawal_audit(company_key,store_id,external_id,actor,previous_hash,new_hash,source_hash,created_at) VALUES (?,?,?,?,?,?,?,NOW())')->execute([$company, $store, $r['external_id'], $actor, $old['row_hash'] ?? null, $r['row_hash'], $hash]);
                $counts[$old ? 'updated' : 'inserted']++;
            }
            $this->pdo()->commit();

            return $counts;
        } catch (Throwable $e) {
            $this->pdo()->rollBack();
            throw $e;
        }
    }

    public function listing(array $filters = []): array
    {
        $scope = $this->scope;
        $where = ['w.company_key=?', 's.archived_at IS NULL'];
        $args = [$scope->companyKey()];
        if (! empty($filters['store_id'])) {
            $scope->assertStoreAccess($filters['store_id']);
            $where[] = 'w.store_id=?';
            $args[] = $filters['store_id'];
        }if (in_array($filters['platform'] ?? '', ['Shopee', 'TikTok Shop'], true)) {
            $where[] = 's.platform=?';
            $args[] = $filters['platform'];
        }
        $field = ($filters['date_basis'] ?? 'requested') === 'completed' ? 'completed_at' : 'requested_at';
        foreach (['date_start' => '>=', 'date_end' => '<'] as $k => $op) {
            if (! empty($filters[$k])) {
                $d = self::date($filters[$k]);
                $where[] = 'w.'.$field.$op.'?';
                $args[] = $k === 'date_end' ? (new DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d H:i:s') : $d;
            }
        }
        if (in_array($filters['status'] ?? '', ['succeeded', 'processing', 'failed', 'cancelled', 'unknown'], true)) {
            $where[] = 'w.normalized_status=?';
            $args[] = $filters['status'];
        }if (! empty($filters['search'])) {
            $where[] = 'w.external_id LIKE ?';
            $args[] = '%'.$filters['search'].'%';
        }
        $from = ' FROM marketplace_withdrawals w JOIN marketplace_stores s ON s.id=w.store_id AND s.company_key=w.company_key WHERE '.implode(' AND ', $where);
        $q = $this->pdo()->prepare('SELECT COUNT(*) total,SUM(CASE WHEN normalized_status=\'succeeded\' AND currency=\'IDR\' THEN net_amount END) paid,SUM(CASE WHEN normalized_status=\'succeeded\' AND currency=\'IDR\' THEN amount END) paid_nominal,SUM(normalized_status=\'processing\') processing,SUM(normalized_status=\'failed\') failed'.$from);
        $q->execute($args);
        $summary = $q->fetch(PDO::FETCH_ASSOC);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $q = $this->pdo()->prepare('SELECT w.*,s.store_name,s.platform'.$from.' ORDER BY w.requested_at DESC,w.id DESC LIMIT 50 OFFSET '.(($page - 1) * 50));
        $q->execute($args);

        return ['rows' => $q->fetchAll(PDO::FETCH_ASSOC), 'summary' => $summary, 'page' => $page, 'pages' => max(1,(int) ceil($summary['total'] / 50))];
    }

    private function pdo(): PDO
    {
        return $this->database->connection()->getPdo();
    }
}
