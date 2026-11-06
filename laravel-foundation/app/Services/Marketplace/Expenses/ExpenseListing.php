<?php

namespace App\Services\Marketplace\Expenses;

use App\Services\Marketplace\MarketplaceScope;
use Illuminate\Database\DatabaseManager;
use PDO;

final class ExpenseListing
{
    public function __construct(private readonly DatabaseManager $database, private readonly MarketplaceScope $scope) {}

    public function list(string $storeId, ?string $category = null, ?string $search = null, ?string $date = null): array
    {
        $this->scope->assertStoreAccess($storeId);
        $params = [$storeId];
        $where = 'WHERE store_id = ?';

        if ($category && $category !== 'Semua Kategori' && $category !== 'Semua') {
            $where .= ' AND category = ?';
            $params[] = $category;
        }

        if ($search) {
            $where .= ' AND (description LIKE ? OR vendor_name LIKE ? OR notes LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        if ($date) {
            $where .= ' AND expense_date = ?';
            $params[] = $date;
        }

        $sql = "SELECT e.* 
                FROM marketplace_expenses e
                $where 
                ORDER BY e.expense_date DESC, e.id DESC";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = 0;
        $profitAffect = 0;
        $cashflowOnly = 0;

        foreach ($rows as $r) {
            $amt = (float) $r['amount'];
            $total += $amt;
            if ($r['affects_profit']) {
                $profitAffect += $amt;
            }
            if ($r['is_cashflow_only']) {
                $cashflowOnly += $amt;
            }
        }

        return [
            'total_amount' => $total,
            'affects_profit' => $profitAffect,
            'cashflow_only' => $cashflowOnly,
            'count' => count($rows),
            'expenses' => $rows,
        ];
    }

    private function pdo(): PDO
    {
        return $this->database->connection()->getPdo();
    }
}
