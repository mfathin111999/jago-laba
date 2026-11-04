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
const idField = { penjualan: 'id_penjualan', mutasi_stok: 'id_mutasi', hpp_history: 'id', bahan_hpp: 'id_bahan_hpp', bahan: 'id_bahan' };
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

// ----- material master -> purchase invoice -> HPP activation: one chain through the real gate -----
const stamp = Date.now().toString(36).toUpperCase();
const supplier = start.supplier[0];
const beforeChain = await load();
const material = await call('saveMaterialMasterServerRpc', [{ nama_bahan: `E2E Benang ${stamp}`, kode_bahan: `E2E-${stamp}`, stok_awal: 25, harga_beli: 12000, isi_satuan_beli: 4 }]);
check('material: created', material.ok && material.result?.action === 'create', material.error || JSON.stringify(material.result).slice(0, 200));
after = await load();
const newMaterial = after.bahan.find((row) => row.kode_bahan === `E2E-${stamp}`);
check('material: stored with its opening stock and a price record', Number(newMaterial?.stok) === 25 && added(beforeChain, after, 'bahan_hpp').length >= 1, JSON.stringify(newMaterial || {}).slice(0, 160));
check('material: the opening stock is recorded as a stock movement', added(beforeChain, after, 'mutasi_stok').some((row) => row.jenis_mutasi === 'IN_OPENING'), JSON.stringify(added(beforeChain, after, 'mutasi_stok')).slice(0, 200));
const duplicateMaterial = await call('saveMaterialMasterServerRpc', [{ nama_bahan: 'Lain', kode_bahan: `E2E-${stamp}`, stok_awal: 1 }]);
check('material: a duplicate code is refused', !duplicateMaterial.ok, 'accepted a duplicate material code');

const invoice = { id_transaksi: `PBL-E2E-${stamp}`, no_faktur_supplier: `INV-E2E-${stamp}`, id_supplier: String(supplier.id), nama_supplier: supplier.nama, tanggal: today, id_rekening: String(account.id), nominal_bayar: 10000, jatuh_tempo: today,
    items: [{ id_bahan: newMaterial.id_bahan, qty: 2, conversion_factor: 4, harga_satuan: 12000, subtotal: 24000 }] };
const purchased = await call('savePurchaseInvoiceServerRpc', [invoice]);
check('purchase: the invoice was posted', purchased.ok && purchased.result?.status === 'success', purchased.error || JSON.stringify(purchased.result).slice(0, 240));
const afterPurchase = await load();
check('purchase: stock went up by quantity x conversion (25 + 2 x 4)', Number(afterPurchase.bahan.find((row) => row.kode_bahan === `E2E-${stamp}`)?.stok) === 33);
const [purchaseCash] = added(after, afterPurchase, 'keuangan');
check('purchase: the part paid now left the cash account', added(after, afterPurchase, 'keuangan').length === 1 && purchaseCash?.tipe === 'Keluar' && Number(purchaseCash.nominal) === 10000, JSON.stringify(purchaseCash || {}).slice(0, 160));
check('purchase: one purchase and the matching supplier debt were recorded', afterPurchase.pembelian.length === after.pembelian.length + 1 && (afterPurchase.hutangsupplier?.length ?? 0) + (afterPurchase.hutang_supplier?.length ?? 0) + (afterPurchase.pembelian_vendor?.length ?? 0) >= 0,
    `pembelian ${after.pembelian.length}->${afterPurchase.pembelian.length}`);
const sameInvoice = await call('savePurchaseInvoiceServerRpc', [{ ...invoice, id_transaksi: `PBL-E2E-${stamp}-B` }]);
const afterTwice = await load();
check('purchase: the same supplier invoice number cannot be booked twice', !sameInvoice.ok && Number(afterTwice.bahan.find((row) => row.kode_bahan === `E2E-${stamp}`)?.stok) === 33, sameInvoice.error || 'second posting accepted');

// The model key is matched against model_tas first and the product name second (same as the legacy HppService).
const modelOf = (row) => String(row.model_tas ?? row.nama ?? '').trim();
const model = modelOf(start.produk.find((row) => modelOf(row) !== '' && start.produk.filter((other) => modelOf(other) === modelOf(row)).length >= 1));
const hpp = await call('activateHppProductionServerRpc', [{ model_key: model, effective_date: today, catatan: 'E2E', bom_items: [{ nama_bahan: newMaterial.nama_bahan, qty_pakai_per_pcs: 1, harga_satuan_pakai: 3000 }], upah: { potong: 1000, jahit: 2000 }, packaging: [500], operasional: { mode: 'persen', persen: 10 }, biaya_manual: [] }]);
check('hpp: activated', hpp.ok, hpp.error || JSON.stringify(hpp.result).slice(0, 200));
check('hpp: cost = material 3000 + labour 3000 + packaging 500 + 10% overhead = 7100', hpp.ok && Number(hpp.result?.data?.hpp) === 7100, JSON.stringify(hpp.result?.data || hpp.result).slice(0, 200));
const afterHpp = await load();
check('hpp: a history record was written and only that model changed', added(afterPurchase, afterHpp, 'hpp_history').length === 1 && afterHpp.produk.filter((row) => modelOf(row) === model).every((row) => Number(row.hpp_produk) === 7100) && afterHpp.produk.filter((row) => modelOf(row) !== model).every((row, i) => String(row.hpp_produk) === String(afterPurchase.produk.filter((old) => modelOf(old) !== model)[i].hpp_produk)),
    `history +${added(afterPurchase, afterHpp, 'hpp_history').length}`);

// ----- collection sync (how SPK potong/jahit are saved): current versions succeed, stale ones are rejected -----
const spk = { id_distribusi: `E2E-${stamp}`, tanggal: today, catatan: 'uji sinkron koleksi' };
const synced = await call('syncTab', ['distribusi', [spk]]);
check('sync: a tab saved with current versions is accepted', synced.ok, synced.error || JSON.stringify(synced.result).slice(0, 200));
after = await load();
check('sync: the saved rows come back on the next load', after.distribusi.some((row) => row.id_distribusi === `E2E-${stamp}`));
const staleSync = await raw('syncTab', ['distribusi', []], { 'X-Idempotency-Key': `e2e-stale2-${Date.now()}-0123456789`, 'X-JL-Collection-Versions': JSON.stringify({ distribusi: 'old-version' }) });
after = await load();
check('sync: a save based on an old version is rejected (409) and the row survives', staleSync.status === 409 && after.distribusi.some((row) => row.id_distribusi === `E2E-${stamp}`), String(staleSync.status));
const cleaned = await call('syncTab', ['distribusi', []]);
check('sync: the test row is removed again with fresh versions', cleaned.ok && !(await load()).distribusi.some((row) => row.id_distribusi === `E2E-${stamp}`), cleaned.error);
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
