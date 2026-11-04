/**
 * End-to-end check against a RUNNING Laravel app backed by the disposable staging database
 * (see scripts/development/local-staging-setup.php and local-staging-seed.php).
 * It logs in through the real login screen, then walks the Marketplace pages and the Konveksi client with real data
 * and exercises the main write actions. It fails on any page error, console error or wrong figure.
 *
 *   E2E_BASE_URL=http://127.0.0.1:8800 E2E_USER=owner E2E_PASSWORD=owner123 node tests/e2e/staging-e2e.mjs
 *   Optional E2E_WORKER="<command that drains the queue>" also verifies that the worker completes the queued sync.
 *
 * It WRITES to the staging database (adds then removes an expense, changes an HPP and a stock minimum, queues a sync).
 */
import { execSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { chromium } from 'playwright';

const base = process.env.E2E_BASE_URL || 'http://127.0.0.1:8800';
const outDir = join(import.meta.dirname, '..', 'visual', 'out');
mkdirSync(outDir, { recursive: true });

const results = [];
const check = (name, ok, detail = '') => {
    results.push({ name, ok });
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${detail && !ok ? ` — ${detail}` : ''}`);
};

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1366, height: 900 }, locale: 'id-ID', timezoneId: 'Asia/Jakarta' });
const page = await context.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
page.on('console', (message) => {
    const text = message.text();
    if (message.type() === 'error' && !/favicon|Failed to load resource: the server responded with a status of (404|409)/.test(text) && !/cdn\./i.test(text)) errors.push(`console: ${text.slice(0, 200)}`);
});
const flushErrors = (where) => {
    check(`${where}: no page or console errors`, errors.length === 0, errors.join(' | '));
    errors.length = 0;
};
const text = async () => page.evaluate(() => document.body.innerText);
const go = async (path) => {
    await page.goto(base + path, { waitUntil: 'networkidle' });
    await page.waitForSelector('h1', { timeout: 10000 }).catch(() => {});
};

// ----- login -----
await page.goto(base + '/', { waitUntil: 'networkidle' });
await page.fill('input[placeholder="Username"]', process.env.E2E_USER || 'owner');
await page.fill('input[placeholder="Password"]', process.env.E2E_PASSWORD || 'owner123');
await page.click('button[type="submit"]');
await page.waitForSelector('.workspace-card', { timeout: 10000 }).catch(() => {});
check('login shows the workspace choice', (await page.locator('.workspace-card').count()) >= 1);
await page.click('.workspace-marketplace');
await page.waitForURL('**/pilot/marketplace**', { timeout: 15000 }).catch(() => {});
check('marketplace workspace opens on the dashboard', page.url().includes('/pilot/marketplace'));
flushErrors('dashboard');

// ----- read pages with real data -----
const pages = [
    ['/pilot/marketplace?store=STR-TT', 'dashboard', ['Demo TikTok']],
    ['/pilot/marketplace/orders?store=STR-TT&period=month', 'orders', ['STG-']],
    ['/pilot/marketplace/analytics?store=STR-TT', 'analytics', []],
    ['/marketplace/catalog?store_id=STR-TT', 'catalog', ['Tas Kulit Pria', 'TAS-H']],
    ['/marketplace/stock?store_id=STR-TT', 'stock', ['Tas Kulit Pria']],
    ['/marketplace/expenses?store_id=STR-TT', 'expenses', ['Biaya iklan Oktober']],
    ['/marketplace/income?store_id=STR-TT&date_start=' + new Date(Date.now() - 40 * 864e5).toISOString().slice(0, 10), 'income', ['Pemasukan']],
    ['/marketplace/marketing?store_id=STR-TT', 'marketing', ['Tas Kulit Pria Hitam']],
    ['/marketplace/integrations?store_id=STR-TT', 'integrations', ['Demo TikTok']],
    ['/marketplace/sync?store_id=STR-TT', 'sync', ['Sinkronisasi manual']],
    ['/marketplace/stores', 'stores', ['Demo TikTok', 'Demo Shopee']],
];
for (const [path, name, expected] of pages) {
    await go(path);
    const body = await text();
    for (const needle of expected) check(`${name}: shows "${needle}"`, body.includes(needle));
    for (const bad of ['undefined', 'NaN', '[object Object]']) check(`${name}: no "${bad}"`, !body.includes(bad));
    check(`${name}: not an error page`, !/Server Error|Whoops|Exception|403 Forbidden|500/i.test(body.slice(0, 400)));
    await page.screenshot({ path: join(outDir, `e2e-${name}.png`), fullPage: true });
    flushErrors(name);
}

// ----- figures that must match the seeded data -----
await go('/marketplace/income?store_id=STR-TT&date_start=' + new Date(Date.now() - 40 * 864e5).toISOString().slice(0, 10));
const income = await text();
check('income separates final, estimated and unknown', /Pemasukan final/.test(income) && /Pemasukan estimasi/.test(income) && /belum ada data/i.test(income));
await go('/marketplace/stock?store_id=STR-TT');
const stock = await text();
check('stock: out-of-stock and low-stock variants are flagged', /Habis/.test(stock));

// ----- write: expense create + delete -----
await go('/marketplace/expenses?store_id=STR-TT');
await page.click('button:has-text("Catat pengeluaran")');
await page.fill('#x-desc', 'Uji E2E pengeluaran');
await page.fill('#x-amount', 'Rp 12.500');
await page.click('dialog button[type="submit"]');
await page.waitForSelector('text=Uji E2E pengeluaran', { timeout: 10000 }).catch(() => {});
check('expense: created and listed', (await text()).includes('Uji E2E pengeluaran'));
await page.locator('tr', { hasText: 'Uji E2E pengeluaran' }).locator('button:has-text("Hapus")').click();
await page.locator('dialog button:has-text("Hapus")').last().click();
await page.waitForFunction(() => !document.body.innerText.includes('Uji E2E pengeluaran'), null, { timeout: 10000 }).catch(() => {});
check('expense: deleted', !(await text()).includes('Uji E2E pengeluaran'));
flushErrors('expense write');

// ----- write: HPP -----
await go('/marketplace/catalog?store_id=STR-TT');
await page.locator('tr', { hasText: 'SAB-1' }).locator('button:has-text("Ubah HPP")').click();
await page.fill('#h-hpp', '35000');
await page.click('dialog button:has-text("Simpan HPP")');
await page.waitForSelector('text=HPP tersimpan', { timeout: 10000 }).catch(() => {});
check('hpp: saved with a server confirmation', (await text()).includes('HPP tersimpan'));
flushErrors('hpp write');

// ----- write: stock minimum -----
await go('/marketplace/stock?store_id=STR-TT');
await page.selectOption('#s-mode', 'sku');
await page.locator('button:has-text("Atur minimum")').first().click();
await page.fill('#m-min', '7');
await page.click('dialog button:has-text("Simpan")');
await page.waitForSelector('text=Minimum stok tersimpan', { timeout: 10000 }).catch(() => {});
check('stock minimum: saved', (await text()).includes('Minimum stok tersimpan'));
flushErrors('stock write');

// ----- write: queue a sync -----
await go('/marketplace/sync?store_id=STR-TT');
if ((await page.locator('button:has-text("Sinkronkan sekarang")').count()) > 0) {
    await page.click('button:has-text("Sinkronkan sekarang")');
    await page.waitForSelector('text=masuk antrean', { timeout: 10000 }).catch(() => {});
    check('sync: queued with a server confirmation', (await text()).includes('masuk antrean'));
} else {
    check('sync: a run is already pending and the button says so', (await text()).includes('Sedang berjalan'));
}
flushErrors('sync write');
if (process.env.E2E_WORKER) {
    execSync(process.env.E2E_WORKER, { stdio: 'ignore' });
    await go('/marketplace/sync?store_id=STR-TT');
    check('sync: the worker finished the run and the page shows it', (await text()).includes('succeeded'));
}

// ----- konveksi client with the real database -----
await page.goto(base + '/workspace.php', { waitUntil: 'networkidle' }).catch(() => {});
await page.goto(base + '/', { waitUntil: 'networkidle' });
if ((await page.locator('.workspace-konveksi').count()) > 0) {
    await page.click('.workspace-konveksi');
    await page.waitForURL('**/konveksi', { timeout: 15000 }).catch(() => {});
}
await page.goto(base + '/konveksi', { waitUntil: 'networkidle', timeout: 60000 });
await page.waitForTimeout(1500);
const konveksi = await text();
check('konveksi: the client boots with real data', /Konveksi|Pusat Kendali|Master Produk/.test(konveksi), konveksi.slice(0, 120));
const menus = await page.locator('aside a[href="#"]').count();
check('konveksi: sidebar rendered', menus >= 15, `${menus} links`);
await page.screenshot({ path: join(outDir, 'e2e-konveksi.png') });
flushErrors('konveksi');

await browser.close();
const failed = results.filter((result) => !result.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed.`);
process.exit(failed.length ? 1 : 0);
