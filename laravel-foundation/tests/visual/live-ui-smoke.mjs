/**
 * Interactive smoke test for the live-mode screens: shipping labels, shipment arrangement and the price editor.
 * Same harness idea as smoke.mjs (real build + recorded props) plus canned answers for the provider endpoints, so the
 * components are actually opened and used in a browser. No provider is contacted.
 *
 * Usage: npm run build && npm run test:visual:live
 */
import { createServer } from 'node:http';
import { existsSync, mkdirSync, readFileSync, statSync } from 'node:fs';
import { extname, join, normalize, resolve } from 'node:path';
import { chromium } from 'playwright';

const root = resolve(import.meta.dirname, '..', '..');
const buildDir = join(root, 'public', 'build');
const fixtureDir = join(import.meta.dirname, 'fixtures');
const outDir = join(import.meta.dirname, 'out');
mkdirSync(outDir, { recursive: true });
const manifest = JSON.parse(readFileSync(join(buildDir, 'manifest.json'), 'utf8'));
const entry = manifest['resources/js/app.ts'];
const css = manifest['resources/css/app.css'];
const TYPES = { '.js': 'text/javascript', '.css': 'text/css' };

const fixture = (name, extra = {}) => ({ ...JSON.parse(readFileSync(join(fixtureDir, `${name}.json`), 'utf8')), ...{ props: { ...JSON.parse(readFileSync(join(fixtureDir, `${name}.json`), 'utf8')).props, ...extra } } });
const PAGES = {
    '/pilot/marketplace/orders': () => fixture('orders', {
        provider: { live: true, can_fulfill: true, seller_center: 'https://seller-id.tokopedia.com/' },
        orders: {
            counts: { all: 2 }, total: 2, page: 1, pages: 1, per_page: 50, tab: 'all', period: { start: '2026-11-01', end: '2026-11-30' },
            rows: ['ORD-1', 'ORD-2'].map((id) => ({ id, date: '2026-11-02 10:00:00', status: 'Perlu Dikirim', amount: 100000, is_cod: false, payment_method: 'Transfer', courier: 'JNE', tracking: '', cancel_requested: false, items: [{ name: 'Tas', sku: 'TAS-H' }] })),
        },
    }),
    '/marketplace/catalog': () => fixture('catalog', { provider: { live: true, can_edit_price: true } }),
};
const calls = [];
const ANSWERS = (path, body) => {
    calls.push(`${path} ${JSON.stringify(body)}`);
    if (path === '/api/marketplace-shipping-document.php') {
        return body.order_id.endsWith('2')
            ? { success: true, documents: [{ package_id: 'PKG-9', success: false, code: 'TIKTOK_API_21023035', message: 'Pengiriman belum diatur.' }] }
            : { success: true, documents: [{ package_id: 'PKG-1', success: true, bytes: 20480, download_url: 'api/marketplace-shipping-document.php?id=abc', preview_url: 'api/marketplace-shipping-document.php?id=abc&preview=1' }] };
    }
    if (path === '/api/marketplace-fulfillment.php') {
        return body.action === 'options'
            ? { success: true, data: { packages: [{ package_id: 'PKG-9', offer_token: 'tok', carrier: 'JNE', service: 'REG', affected_orders: [body.order_id], methods: [{ id: 'PICKUP', name: 'Pickup', slots: [{ start_time: 1793750400, end_time: 1793754000 }] }, { id: 'DROP_OFF', name: 'Drop off', slots: [] }] }] } }
            : { success: true, data: { confirmed: true } };
    }
    if (path === '/api/marketplace-price.php') {
        return body.action === 'prepare'
            ? { success: true, data: { product_name: 'Tas Kulit Pria', store_name: 'Toko A', rows: [{ external_variant_id: 'V1', variant_name: 'Hitam', sku: 'TAS-H', base_price: 100000, editable: true, fee_rate: 0.1, promotions: [] }] } }
            : { success: true, data: { status: 'verified', message: 'Harga tersinkron.', rows: [{ variant_id: 'V1', status: 'verified' }] } };
    }
    return { success: false, message: 'not mocked' };
};

const server = createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname.startsWith('/build/')) {
        const file = normalize(join(buildDir, url.pathname.slice('/build/'.length)));
        if (file.startsWith(buildDir) && existsSync(file) && statSync(file).isFile()) {
            response.writeHead(200, { 'Content-Type': TYPES[extname(file)] ?? 'application/octet-stream' });
            response.end(readFileSync(file));

            return;
        }
    }
    if (url.pathname.startsWith('/api/')) {
        let raw = '';
        request.on('data', (chunk) => (raw += chunk));
        request.on('end', () => {
            response.writeHead(200, { 'Content-Type': 'application/json' });
            response.end(JSON.stringify(ANSWERS(url.pathname, raw ? JSON.parse(raw) : {})));
        });

        return;
    }
    const factory = PAGES[url.pathname];
    if (!factory) {
        response.writeHead(404);
        response.end();

        return;
    }
    const page = { ...factory(), url: url.pathname + url.search };
    response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    response.end(`<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="harness"><link rel="stylesheet" href="/build/${css.file}"><script type="module" src="/build/${entry.file}"></script></head><body><script data-page="app" type="application/json">${JSON.stringify(page).replace(/</g, '\\u003C')}</script><div id="app"></div></body></html>`);
});

await new Promise((done) => server.listen(0, '127.0.0.1', done));
const base = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch();
const failures = [];
const check = (name, ok, detail = '') => {
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${!ok && detail ? ` — ${detail}` : ''}`);
    if (!ok) failures.push(name);
};

for (const viewport of [{ name: 'desktop', width: 1366, height: 900 }, { name: 'mobile', width: 390, height: 844 }]) {
    const context = await browser.newContext({ viewport: { width: viewport.width, height: viewport.height }, locale: 'id-ID' });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => message.type() === 'error' && !/Failed to load resource/.test(message.text()) && errors.push(message.text()));
    const text = () => page.evaluate(() => document.body.innerText);

    // ----- orders: labels + arrangement -----
    await page.goto(`${base}/pilot/marketplace/orders`, { waitUntil: 'networkidle' });
    const boxes = page.locator('tbody input[type="checkbox"]');
    check(`${viewport.name}: live orders show a selection column`, (await boxes.count()) > 0);
    await boxes.first().check();
    await page.getByRole('button', { name: /Cetak resi \(1\)/ }).click();
    await page.waitForSelector('text=Unduh PDF', { timeout: 8000 }).catch(() => {});
    check(`${viewport.name}: a prepared label offers download and preview`, (await text()).includes('Unduh PDF') && (await text()).includes('Pratinjau PDF') && (await text()).includes('20 KB'));
    check(`${viewport.name}: the download link points at the shipping document endpoint`, (await page.locator('a:has-text("Unduh PDF")').first().getAttribute('href')) === '/api/marketplace-shipping-document.php?id=abc');
    await page.keyboard.press('Escape');

    // ----- an order whose package still needs a shipment arrangement (the mock decides by the order id suffix) -----
    await page.evaluate(() => document.querySelectorAll('dialog[open]').forEach((dialog) => dialog.close()));
    await boxes.first().uncheck();
    await boxes.nth(1).check();
    calls.length = 0;
    await page.getByRole('button', { name: /Cetak resi \(1\)/ }).click();
    await page.getByRole('button', { name: 'Atur pengiriman' }).click({ timeout: 8000 });
    await page.waitForSelector('text=Metode pengiriman', { timeout: 8000 }).catch(() => {});
    check(`${viewport.name}: arranging a shipment lists the methods the marketplace offers`, (await text()).includes('Kurir / layanan: JNE - REG') && (await text()).includes('Pickup'));
    await page.selectOption('dialog[open] select >> nth=0', 'PICKUP');
    check(`${viewport.name}: choosing pickup asks for a pickup slot`, (await text()).includes('Jadwal pickup (WIB)'));
    await page.selectOption('dialog[open] select >> nth=1', { index: 1 });
    await page.locator('dialog[open] input[type="checkbox"]').check();
    await page.getByRole('button', { name: /Konfirmasi pengiriman/ }).click();
    await page.waitForTimeout(600);
    check(`${viewport.name}: the confirmation carried the consent, offer token, method and slot`, calls.some((call) => call.includes('"action":"confirm"') && call.includes('"confirmed":"1"') && call.includes('"offer_token":"tok"') && call.includes('"method":"PICKUP"') && call.includes('"slot_start":"1793750400"')), calls.join(' | ').slice(0, 400));

    // (kept from the first pass) the page still works after the dialogs

    await page.evaluate(() => document.querySelectorAll('dialog[open]').forEach((dialog) => dialog.close()));
    const firstId = (await page.locator('tbody tr').first().locator('.link-button').first().innerText()).trim();
    calls.length = 0;
    await page.evaluate(() => document.querySelectorAll('dialog[open]').forEach((dialog) => dialog.close()));
    // drive the arrangement flow through the component with an order id the mock treats as unarranged
    await page.evaluate(() => (window).__setOrder2 = true);
    await page.screenshot({ path: join(outDir, `live-orders-${viewport.name}.png`), fullPage: false });
    check(`${viewport.name}: the first order id is readable (${firstId})`, firstId.length > 0);
    check(`${viewport.name}: no page errors on live orders`, errors.length === 0, errors.join(' | '));
    errors.length = 0;

    // ----- catalog: price editor -----
    await page.goto(`${base}/marketplace/catalog`, { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Ubah harga' }).first().click();
    await page.waitForSelector('text=Harga dasar', { timeout: 8000 }).catch(() => {});
    check(`${viewport.name}: the price editor lists the variants read from the marketplace`, (await text()).includes('TAS-H') && (await text()).includes('Estimasi biaya MP 10.0%') === false);
    const input = page.locator('dialog[open] input[type="number"]').first();
    await input.fill('120000');
    check(`${viewport.name}: the estimated net price follows the typed price`, (await text()).includes('Estimasi biaya MP 10.0%') && (await text()).includes('108.000'));
    await page.getByRole('button', { name: /Simpan & sinkronkan/ }).click();
    await page.waitForSelector('text=Harga tersinkron.', { timeout: 8000 }).catch(() => {});
    check(`${viewport.name}: saving shows the marketplace answer and locks further edits`, (await text()).includes('Harga tersinkron.') && (await page.getByRole('button', { name: /Simpan & sinkronkan/ }).isDisabled()));
    check(`${viewport.name}: the save call carried an operation key and the typed price`, calls.some((call) => call.includes('"action":"save"') && call.includes('operation_key') && call.includes('120000')), calls.join(' | ').slice(0, 300));
    await page.screenshot({ path: join(outDir, `live-price-${viewport.name}.png`) });
    check(`${viewport.name}: no page errors on the price editor`, errors.length === 0, errors.join(' | '));
    await context.close();
}

await browser.close();
server.close();
console.log(failures.length ? `\n${failures.length} problem(s).` : '\nLive-mode screens work.');
process.exit(failures.length ? 1 : 0);
