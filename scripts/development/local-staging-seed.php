<?php

declare(strict_types=1);

/**
 * Fills the DISPOSABLE staging database with deterministic SYNTHETIC Marketplace data (never real customer data) so
 * every page has something to show: two stores, orders with final / estimated / unknown income, expenses, catalog
 * and stock, HPP history, ad reports and withdrawals.
 *
 *   JL_DB_HOST=127.0.0.1 JL_DB_PORT=3320 JL_DB_NAME=jl_staging_a JL_DB_USER=root JL_DB_PASS= \
 *   php scripts/development/local-staging-seed.php
 */
$name = getenv('JL_DB_NAME') ?: '';
$host = getenv('JL_DB_HOST') ?: '';
// Allowed: a name starting with jl_staging, or exactly the name in JL_SETUP_ALLOW_NAME (the local launcher sets it), on loopback only.
$allowedName = preg_match('/^jl_staging[a-z0-9_]*$/', $name) === 1 || (getenv('JL_SETUP_ALLOW_NAME') === $name && preg_match('/^[a-z0-9_]+$/i', $name) === 1);
if (! $allowedName || ! in_array($host, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Refusing: JL_DB_NAME must start with jl_staging (or equal JL_SETUP_ALLOW_NAME) and JL_DB_HOST must be loopback.\n");
    exit(1);
}
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int) (getenv('JL_DB_PORT') ?: 3306), $name), getenv('JL_DB_USER') ?: 'root', getenv('JL_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$company = getenv('JL_COMPANY_KEY') ?: 'LOCAL-COMPANY';
if ((int) $pdo->query('SELECT COUNT(*) FROM marketplace_orders')->fetchColumn() > 0) {
    fwrite(STDERR, "Orders already exist; this seeder only fills an empty staging database.\n");
    exit(1);
}
// A previous interrupted run may have left partial rows; start from a clean slate for the seeded stores.
foreach (['marketplace_catalog_variants', 'marketplace_sync_records', 'marketplace_hpp_history', 'marketplace_product_ads', 'marketplace_expenses', 'marketplace_withdrawals', 'marketplace_oauth_connections'] as $table) {
    $pdo->exec("DELETE FROM `$table` WHERE store_id IN ('STR-TT', 'STR-SP')");
}
$pdo->exec("DELETE FROM marketplace_stores WHERE id IN ('STR-TT', 'STR-SP')");
mt_srand(20261104);
$insert = static function (string $table, array $row) use ($pdo): int {
    $columns = implode(',', array_map(static fn (string $c): string => "`$c`", array_keys($row)));
    $marks = implode(',', array_fill(0, count($row), '?'));
    $pdo->prepare("INSERT INTO `$table` ($columns) VALUES ($marks)")->execute(array_values($row));

    return (int) $pdo->lastInsertId();
};
$day = static fn (int $ago, int $hour = 10): string => date('Y-m-d H:i:s', strtotime("-$ago days") - ((int) date('G') - $hour) * 3600);

$stores = [
    ['id' => 'STR-TT', 'platform' => 'TikTok Shop', 'store_name' => 'Demo TikTok', 'store_identifier' => 'tt-demo', 'status' => 'Terhubung', 'authorization_status' => 'verified'],
    ['id' => 'STR-SP', 'platform' => 'Shopee', 'store_name' => 'Demo Shopee', 'store_identifier' => 'sp-demo', 'status' => 'Terputus', 'authorization_status' => 'pending'],
];
foreach ($stores as $store) {
    $insert('marketplace_stores', $store + ['company_key' => $company, 'default_admin_fee_pct' => 8, 'default_free_shipping_pct' => 4, 'default_payment_fee_pct' => 1.5, 'sync_mode' => 'API', 'created_at' => $day(60), 'updated_at' => $day(1)]);
}
$insert('marketplace_oauth_connections', [
    'company_key' => $company, 'store_id' => 'STR-TT', 'platform' => 'TikTok Shop', 'external_store_id' => 'ext-tt-1', 'external_store_name' => 'Demo TikTok',
    'access_token_encrypted' => 'staging-not-a-real-token', 'refresh_token_encrypted' => 'staging-not-a-real-token', 'token_expires_at' => $day(-30), 'authorized_at' => $day(20), 'verified_at' => $day(20),
    'last_successful_sync_at' => $day(1), 'status' => 'verified', 'created_at' => $day(20), 'updated_at' => $day(1),
]);

$products = [
    ['P-TAS', 'Tas Kulit Pria', [['V-TAS-H', 'TAS-H', 'Hitam', 189000, 12, 90000], ['V-TAS-C', 'TAS-C', 'Cokelat', 189000, 3, 90000]]],
    ['P-DOM', 'Dompet Lipat', [['V-DOM-S', 'DOM-S', 'Standar', 79000, 0, 30000]]],
    ['P-SAB', 'Sabuk Kulit', [['V-SAB-1', 'SAB-1', 'M', 99000, 25, null]]],
];
foreach ($products as [$productId, $productName, $variants]) {
    $skus = [];
    foreach ($variants as [$variantId, $sku, $variantName, $price, $stock, $hpp]) {
        $insert('marketplace_catalog_variants', ['store_id' => 'STR-TT', 'external_product_id' => $productId, 'external_variant_id' => $variantId, 'product_name' => $productName, 'parent_sku' => $productId, 'sku' => $sku, 'variant_name' => $variantName, 'price' => $price, 'original_price' => $price, 'stock_qty' => $stock, 'catalog_visible' => 1, 'source_status' => 'ACTIVATE', 'source_updated_at' => $day(1)]);
        $skus[] = ['id' => $variantId, 'seller_sku' => $sku, 'inventory' => [['warehouse_id' => 'WH-1', 'quantity' => $stock]]];
        if ($hpp !== null) {
            $insert('marketplace_hpp_history', ['store_id' => 'STR-TT', 'marketplace_sku' => $sku, 'unit_hpp' => $hpp, 'effective_from' => date('Y-m-d', strtotime('-45 days')), 'source' => 'MANUAL', 'reason' => 'staging seed', 'changed_by' => 'staging']);
        }
    }
    $insert('marketplace_sync_records', ['store_id' => 'STR-TT', 'resource_type' => 'product', 'external_id' => $productId, 'payload_json' => json_encode(['id' => $productId, 'title' => $productName, 'skus' => $skus]), 'source_updated_at' => $day(1), 'fetched_at' => $day(1)]);
}

$flat = [];
foreach ($products as [, $productName, $variants]) {
    foreach ($variants as $variant) {
        $flat[] = [$productName, ...$variant];
    }
}
$statusPool = [['COMPLETED', 'Selesai'], ['COMPLETED', 'Selesai'], ['DELIVERED', 'Selesai'], ['IN_TRANSIT', 'Terkirim'], ['AWAITING_SHIPMENT', 'Pending'], ['CANCELLED', 'Dibatalkan'], ['COMPLETED', 'Selesai']];
for ($i = 1; $i <= 28; $i++) {
    [$productName, , $sku, $variantName, $price, , $hpp] = $flat[$i % count($flat)];
    [$source, $enum] = $statusPool[$i % count($statusPool)];
    $qty = 1 + ($i % 3);
    $gross = $price * $qty;
    $fees = round($gross * 0.12);
    $kind = $i % 4; // 0 estimated, 1 unknown, 2-3 final
    $snapshot = ['source_status' => $source, 'gross_amount' => $gross, 'payment_method' => 'Transfer', 'is_cod' => false, 'total_cogs' => $hpp === null ? null : $hpp * $qty, 'tracking_number' => $source === 'AWAITING_SHIPMENT' ? '' : 'RESI'.(1000 + $i), 'courier' => 'JNE',
        'items' => [['name' => $productName, 'variant' => $variantName, 'sku' => $sku, 'quantity' => $qty, 'unit_price' => $price, 'total_price' => $gross]]];
    if ($kind === 1 || $source === 'CANCELLED') {
        $snapshot += ['net_income' => null, 'total_fees' => null];
    } else {
        $snapshot += ['net_income' => $gross - $fees, 'total_fees' => $fees, 'is_estimated' => $kind === 0];
    }
    $orderId = sprintf('STG-%04d', $i);
    $insert('marketplace_orders', [
        'id' => $orderId, 'store_id' => 'STR-TT', 'order_date' => $day(($i % 25) + 1, 9 + $i % 8), 'buyer_username' => 'pembeli'.$i, 'order_status' => $enum, 'gross_sales' => $gross,
        'net_settlement_amount' => $snapshot['net_income'] ?? 0, 'total_cogs_hpp' => ($hpp ?? 0) * $qty, 'payout_batch_id' => $kind >= 2 ? 'BATCH-'.(1 + $i % 3) : null,
        'created_at' => $day(($i % 25) + 1), 'internal_state' => 'active', 'source_kind' => 'api', 'transaction_detail_json' => json_encode($snapshot),
    ]);
    $insert('marketplace_order_items', ['order_id' => $orderId, 'sku' => $sku, 'item_name' => $productName.' '.$variantName, 'qty' => $qty, 'selling_price' => $price, 'unit_cogs_hpp' => $hpp ?? 0, 'subtotal_price' => $gross, 'subtotal_cogs' => ($hpp ?? 0) * $qty]);
}

foreach ([['Biaya iklan Oktober', 'Biaya Iklan/Advertising Marketplace', 450000, 1, 0], ['Kemasan dan bubble wrap', 'Operasional', 120000, 1, 0], ['Setoran kas', 'Kas', 500000, 0, 1], ['Biaya foto produk', 'Operasional', 250000, 1, 0], ['Langganan aplikasi', 'Operasional', 99000, 1, 0]] as $n => [$desc, $category, $amount, $affects, $cash]) {
    $insert('marketplace_expenses', ['store_id' => 'STR-TT', 'description' => $desc, 'vendor_name' => 'Vendor '.($n + 1), 'expense_date' => date('Y-m-d', strtotime('-'.(3 + $n * 4).' days')), 'category' => $category, 'amount' => $amount, 'payment_method' => 'Transfer Bank', 'affects_profit' => $affects, 'is_cashflow_only' => $cash, 'created_at' => $day(3 + $n * 4)]);
}

foreach ([['I-TAS-H', 'TAS-H', 'Tas Kulit Pria Hitam', 300000, 1200000, 6, 6, 90000, 30000], ['I-DOM-S', 'DOM-S', 'Dompet Lipat', 250000, 0, 0, 0, 30000, 0], ['I-SAB-1', 'SAB-1', 'Sabuk Kulit M', 90000, 450000, 5, 5, 0, 20000]] as [$item, $sku, $productName, $spend, $revenue, $orders, $qty, $hpp, $fee]) {
    $insert('marketplace_product_ads', ['store_id' => 'STR-TT', 'platform' => 'TikTok Shop', 'ad_type' => 'Produk', 'campaign_name' => 'Kampanye Staging', 'marketplace_item_id' => $item, 'sku' => $sku, 'product_name' => $productName, 'ad_spend' => $spend, 'gross_revenue' => $revenue, 'orders_count' => $orders, 'qty_sold' => $qty, 'unit_cogs_hpp' => $hpp, 'platform_fee' => $fee, 'affiliate_fee' => 0, 'period_start' => date('Y-m-01'), 'period_end' => date('Y-m-d'), 'last_synced_at' => $day(1), 'created_at' => $day(1)]);
}

$withdrawalColumns = array_column($pdo->query('SHOW COLUMNS FROM marketplace_withdrawals')->fetchAll(PDO::FETCH_ASSOC), 'Field');
foreach ([['WD-STG-1', 'succeeded', 'PAID', 1500000, 1500, 1498500], ['WD-STG-2', 'processing', 'PROCESSING', 800000, 1500, 798500], ['WD-STG-3', 'failed', 'FAILED', 300000, 0, 300000]] as $n => [$externalId, $normalized, $source, $amount, $fee, $net]) {
    $row = ['company_key' => $company, 'store_id' => 'STR-TT', 'external_id' => $externalId, 'withdrawal_type' => 'Withdrawal', 'requested_at' => $day(5 + $n * 6), 'completed_at' => $normalized === 'succeeded' ? $day(4 + $n * 6) : null,
        'amount' => $amount, 'fee' => $fee, 'net_amount' => $net, 'currency' => 'IDR', 'source_status' => $source, 'normalized_status' => $normalized, 'bank_name' => 'BCA', 'account_mask' => '•••• 4321',
        'failure_reason' => $normalized === 'failed' ? 'Rekening tidak valid (data uji)' : null, 'source_kind' => 'import', 'source_hash' => str_repeat('a', 64), 'row_hash' => hash('sha256', $externalId), 'imported_by' => 'staging', 'imported_at' => $day(1), 'updated_at' => $day(1)];
    $insert('marketplace_withdrawals', array_intersect_key($row, array_flip($withdrawalColumns)));
}

printf("seeded: %d orders, %d items, %d expenses, %d catalog variants, %d ad rows, %d withdrawals\n",
    $pdo->query('SELECT COUNT(*) FROM marketplace_orders')->fetchColumn(), $pdo->query('SELECT COUNT(*) FROM marketplace_order_items')->fetchColumn(),
    $pdo->query('SELECT COUNT(*) FROM marketplace_expenses')->fetchColumn(), $pdo->query('SELECT COUNT(*) FROM marketplace_catalog_variants')->fetchColumn(),
    $pdo->query('SELECT COUNT(*) FROM marketplace_product_ads')->fetchColumn(), $pdo->query('SELECT COUNT(*) FROM marketplace_withdrawals')->fetchColumn());
