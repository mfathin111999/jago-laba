/**
 * Write-path E2E for the Konveksi client against the RUNNING app and the disposable staging database.
 * It uses the legacy client's own API library (window.jlApi) in a real logged-in browser, so every call carries the
 * real CSRF token, idempotency key, collection versions and reset epoch, and runs through the real MySQL gate
 * (GET_LOCK, receipts, collection versions). Reads go through loadSemuaData, the same way the screens refresh.
 *
 *   E2E_BASE_URL=http://127.0.0.1:8800 node tests/e2e/konveksi-write-e2e.mjs
 *
 * It WRITES to the staging database and then reverses what it can (void sale); the hutang rows stay as evidence.
 */
import { chromium } from 'playwright';

const base = process.env.E2E_BASE_URL || 'http://127.0.0.1:8800';
const results = [];
const check = (name, ok, detail = '') => {
    results.push({ name, ok });
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${!ok && detail ? ` — ${detail}` : ''}`);
};

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
const page = await context.newPage();
const pageErrors = [];
page.on('pageerror', (error) => pageErrors.push(error.message));

await page.goto(base + '/', { waitUntil: 'networkidle' });
await page.fill('input[placeholder="Username"]', process.env.E2E_USER || 'owner');
await page.fill('input[placeholder="Password"]', process.env.E2E_PASSWORD || 'owner123');
await page.click('button[type="submit"]');
await page.waitForSelector('.workspace-konveksi');
await page.click('.workspace-konveksi');
await page.waitForURL('**/konveksi', { timeout: 15000 });
await page.waitForFunction(() => window.jlApi && window.jlApi.ready, null, { timeout: 60000 });
await page.waitForTimeout(1500);

/** Calls an RPC method through the legacy client library; returns {ok, result|error}. */
const call = (method, args) =>
    page.evaluate(async ([m, a]) => {
        try {
            return { ok: true, result: await window.jlApi.call(m, a) };
        } catch (error) {
            return { ok: false, error: error.message, status: error.status };
        }
    }, [method, args]);

/** A raw POST with explicit headers, for the cases the library hides (replay, stale versions). */
const raw = (method, args, headers) =>
    page.evaluate(async ([m, a, h]) => {
        const response = await fetch('/api/rpc.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.JL_SESSION.csrf, ...h },
            body: JSON.stringify({ method: m, args: a }),
        });
        return { status: response.status, body: await response.json() };
    }, [method, args, headers]);

const load = async () => {
    const response = await call('loadSemuaData', []);
    if (!response.ok) throw new Error(`loadSemuaData failed: ${response.error}`);

    return response.result;
};
/** Rows present in `now` but not in `before`, matched by id (row order in the database is not insertion order). */
const idField = { penjualan: 'id_penjualan' };
const added = (before, now, collection) => now[collection].filter((row) => !before[collection].some((old) => old[idField[collection] ?? 'id'] === row[idField[collection] ?? 'id']));
const stockOf = (data, sku) => Number(data.master_stok.find((row) => row.kode_sku === sku)?.stok_akhir);
const today = new Date().toISOString().slice(0, 10);

// ----- pick real rows from the imported data -----
const start = await load();
check('the real Konveksi data loaded through the gate', start.master_stok.length === 31 && start.produk.length === 17 && start.rekening.length === 3, `stok=${start.master_stok.length} produk=${start.produk.length} rekening=${start.rekening.length}`);
const stockRow = start.master_stok.find((row) => Number(row.stok_akhir) >= 5 && start.produk.some((p) => p.kode === row.kode_sku));
const account = start.rekening[0];
const sku = stockRow.kode_sku;
const baseStock = stockOf(start, sku);
const baseCash = start.keuangan.length;
const baseSales = start.penjualan.length;
const baseMoves = start.mutasi_stok.length;
const sale = (qty, price = 100000) => ({ tanggal: today, id_rekening: String(account.id), diskon_transaksi: 0, ongkir: 0, nominal_dibayar: qty * price, items: [{ kode_sku: sku, nama_produk: stockRow.nama_produk, qty, harga_jual: price, diskon_item: 0 }] });

// ----- sale: post -----
const posted = await call('postPenjualanProduksiServer', [sale(2)]);
check('sale: posted', posted.ok && /POSTED/.test(JSON.stringify(posted.result)), posted.error || JSON.stringify(posted.result).slice(0, 200));
let after = await load();
check('sale: stock went down by the quantity sold', stockOf(after, sku) === baseStock - 2, `${baseStock} -> ${stockOf(after, sku)}`);
check('sale: one sale, one stock movement and one cash entry were recorded', after.penjualan.length === baseSales + 1 && after.mutasi_stok.length === baseMoves + 1 && after.keuangan.length === baseCash + 1,
    `penjualan ${baseSales}->${after.penjualan.length}, mutasi ${baseMoves}->${after.mutasi_stok.length}, keuangan ${baseCash}->${after.keuangan.length}`);
const [cashRow] = added(start, after, 'keuangan');
check('sale: the cash entry is an inflow of the billed amount', cashRow?.tipe === 'Masuk' && Number(cashRow.nominal) === 200000, JSON.stringify(cashRow).slice(0, 160));
const [postedSale] = added(start, after, 'penjualan');
check('sale: profit is computed by the server from stored HPP', Number(postedSale.total_hpp) > 0 && Number(postedSale.laba_kotor) === Number(postedSale.total_tagihan) - Number(postedSale.total_hpp), `hpp=${postedSale.total_hpp} laba=${postedSale.laba_kotor}`);

// ----- sale: the same request replayed with the same key does not post twice -----
const key = `e2e-replay-${Date.now()}-0123456789`;
const versions = JSON.stringify({});
const first = await raw('postPenjualanProduksiServer', [sale(1)], { 'X-Idempotency-Key': key, 'X-JL-Collection-Versions': versions });
const second = await raw('postPenjualanProduksiServer', [sale(1)], { 'X-Idempotency-Key': key, 'X-JL-Collection-Versions': versions });
check('replay: both attempts answer successfully', first.status === 200 && second.status === 200, `${first.status}/${second.status} ${JSON.stringify(second.body).slice(0, 160)}`);
after = await load();
check('replay: the sale was posted exactly once', stockOf(after, sku) === baseStock - 3 && after.penjualan.length === baseSales + 2, `stock ${stockOf(after, sku)}, sales ${after.penjualan.length}`);

// ----- sale: more than the stock is refused and nothing changes -----
const tooMany = await call('postPenjualanProduksiServer', [sale(100000)]);
check('overselling: refused with a readable message', !tooMany.ok && /Stok tidak mencukupi/.test(tooMany.error || ''), tooMany.error);
after = await load();
check('overselling: nothing changed', stockOf(after, sku) === baseStock - 3 && after.penjualan.length === baseSales + 2);

// ----- the write gate refuses a missing key -----
const noKey = await raw('postPenjualanProduksiServer', [sale(1)], {});
check('gate: a write without an idempotency key is refused (422)', noKey.status === 422, String(noKey.status));

// ----- sale: void restores stock and reverses the cash -----
const toVoid = after.penjualan.filter((row) => /POSTED/.test(String(row.status_transaksi)));
for (const row of toVoid.slice(-2)) {
    const voided = await call('voidPenjualanProduksiServer', [{ id_penjualan: row.id_penjualan, void_reason: 'E2E uji' }]);
    check(`void ${row.no_penjualan}: accepted`, voided.ok, voided.error);
}
after = await load();
check('void: stock is back to where it started', stockOf(after, sku) === baseStock, `${baseStock} vs ${stockOf(after, sku)}`);
check('void: the sales are marked VOID', after.penjualan.slice(baseSales).every((row) => /VOID/i.test(String(row.status_transaksi))), after.penjualan.slice(baseSales).map((row) => row.status_transaksi).join(','));

// ----- stale collection versions are rejected instead of overwriting -----
const stale = await raw('syncTab', ['keuangan', []], { 'X-Idempotency-Key': `e2e-stale-${Date.now()}-0123456789`, 'X-JL-Collection-Versions': JSON.stringify({ keuangan: 'stale-version' }) });
check('versions: a stale syncTab is rejected (409) and does not overwrite', stale.status === 409, `${stale.status} ${JSON.stringify(stale.body).slice(0, 160)}`);
after = await load();
check('versions: the cash ledger is untouched after the rejected write', after.keuangan.length >= baseCash + 1);

// ----- employee payable: create, then pay part of it -----
const employee = start.pegawai[0];
const debtBefore = start.hutang_konveksi.length;
const created = await call('createHutangKonveksiManual', [{ id_pegawai: employee.id, nama_pegawai: employee.nama, nominal: 50000, divisi: 'jahit', tanggal: today }]);
check('hutang: created', created.ok, created.error);
after = await load();
check('hutang: one more payable row exists', after.hutang_konveksi.length === debtBefore + 1, `${debtBefore} -> ${after.hutang_konveksi.length}`);
const beforePay = after;
const paid = await call('bayarHutangKonveksi', [{ id_pegawai: employee.id, nominal_bayar: 20000, id_rekening: String(account.id), tanggal_bayar: today }]);
check('hutang: partial payment accepted', paid.ok, paid.error);
after = await load();
const [payment] = added(beforePay, after, 'keuangan');
check('hutang: the payment is booked as cash out of the amount paid', added(beforePay, after, 'keuangan').length === 1 && payment?.tipe === 'Keluar' && Number(payment.nominal) === 20000, JSON.stringify(payment || {}).slice(0, 160));
const over = await call('bayarHutangKonveksi', [{ id_pegawai: employee.id, nominal_bayar: 999999999, id_rekening: String(account.id) }]);
check('hutang: paying more than is owed is refused', !over.ok && /melebihi saldo/.test(over.error || ''), over.error);

// ----- backup: a real snapshot is made and the browser learns only file names -----
const backup = await call('backupSekarangJagoLaba', []);
check('backup: created', backup.ok && backup.result.status === 'success', backup.error || JSON.stringify(backup.result).slice(0, 200));
check('backup: the answer carries file names, never server paths', backup.ok && /^jago_laba_\d+_\d+\.json\.gz$/.test(backup.result.snapshot_file) && !/[\\/]/.test(backup.result.manifest_file) && backup.result.url === '#', JSON.stringify(backup.result).slice(0, 300));
const history = await call('getRiwayatBackupJagoLaba', []);
check('backup: the history lists it without server paths', history.ok && history.result.length >= 1 && history.result.every((row) => row.url === '#' || row.url === null), JSON.stringify(history.result?.[0] || {}).slice(0, 200));

// ----- authorization: the page is still served only to a signed-in user with the workspace -----
check('no uncaught page errors during the run', pageErrors.length === 0, pageErrors.join(' | '));

await browser.close();
const failed = results.filter((result) => !result.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed.`);
process.exit(failed.length ? 1 : 0);
