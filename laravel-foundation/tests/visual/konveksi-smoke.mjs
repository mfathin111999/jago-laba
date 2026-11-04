/**
 * Smoke test for the Konveksi client served by Laravel (KonveksiLegacyAppController).
 * It renders the real page, answers /api/rpc.php with a recorded loadSemuaData response, opens the app at desktop and
 * mobile width and clicks every sidebar menu, failing on any uncaught page error or app-level console error.
 *
 * The legacy client loads Tailwind, Vue and icon fonts from public CDNs, so this test needs internet access.
 * Usage: PHP_BIN=path/to/php8.4 npm run test:visual:konveksi
 */
import { execFileSync } from 'node:child_process';
import { createServer } from 'node:http';
import { existsSync, mkdirSync, readFileSync, statSync } from 'node:fs';
import { extname, join, normalize, resolve } from 'node:path';
import { chromium } from 'playwright';

const root = resolve(import.meta.dirname, '..', '..');
const outDir = join(import.meta.dirname, 'out');
mkdirSync(outDir, { recursive: true });

const html = execFileSync(process.env.PHP_BIN || 'php', [join(import.meta.dirname, 'render-konveksi.php')], { cwd: root, maxBuffer: 64 * 1024 * 1024 }).toString('utf8');
const load = readFileSync(join(import.meta.dirname, 'fixtures', 'konveksi-load.json'), 'utf8');
const TYPES = { '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' };
const rpcCalls = [];

const server = createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname === '/konveksi') {
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        response.end(html);

        return;
    }
    if (url.pathname === '/api/rpc.php') {
        let body = '';
        request.on('data', (chunk) => (body += chunk));
        request.on('end', () => {
            const call = JSON.parse(body || '{}');
            rpcCalls.push({ method: call.method, csrf: request.headers['x-csrf-token'], key: request.headers['x-idempotency-key'] });
            response.writeHead(200, { 'Content-Type': 'application/json' });
            response.end(call.method === 'loadSemuaData' ? load : JSON.stringify({ ok: true, result: {} }));
        });

        return;
    }
    const file = normalize(join(root, 'public', url.pathname));
    if (url.pathname.startsWith('/assets/') && file.startsWith(join(root, 'public')) && existsSync(file) && statSync(file).isFile()) {
        response.writeHead(200, { 'Content-Type': TYPES[extname(file)] ?? 'application/octet-stream' });
        response.end(readFileSync(file));

        return;
    }
    response.writeHead(404);
    response.end();
});

await new Promise((done) => server.listen(0, '127.0.0.1', done));
const base = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch();
const failures = [];
const fail = (where, what) => {
    failures.push(`${where}: ${what}`);
    console.log(`FAIL ${where}: ${what}`);
};

for (const viewport of [{ name: 'desktop', width: 1366, height: 900 }, { name: 'mobile', width: 390, height: 844 }]) {
    const context = await browser.newContext({ viewport: { width: viewport.width, height: viewport.height }, locale: 'id-ID' });
    const page = await context.newPage();
    page.on('pageerror', (error) => fail(viewport.name, `pageerror: ${error.message}`));
    page.on('console', (message) => {
        const text = message.text();
        if (message.type() === 'error' && /JAGO LABA|Runtime Error|Promise Error|Vue warn/i.test(text)) fail(viewport.name, `console: ${text.slice(0, 200)}`);
    });

    try {
        await page.goto(`${base}/konveksi`, { waitUntil: 'networkidle', timeout: 60000 });
    } catch (error) {
        fail(viewport.name, `page did not load (CDN reachable?): ${error.message.split('\n')[0]}`);
        await context.close();
        continue;
    }

    const loaded = rpcCalls.some((call) => call.method === 'loadSemuaData');
    if (!loaded) fail(viewport.name, 'the client never called loadSemuaData');
    const sent = rpcCalls.find((call) => call.method === 'loadSemuaData');
    if (sent && sent.csrf !== 'smoke-csrf-token') fail(viewport.name, 'the client did not send the Laravel CSRF token');

    const menus = await page.locator('aside a[href="#"], nav a[href="#"]').count();
    if (menus < 15) fail(viewport.name, `only ${menus} sidebar menus rendered (expected ~24)`);
    console.log(`${viewport.name}: ${menus} menu links, rpc calls so far: ${rpcCalls.length}`);

    if (viewport.name === 'desktop') {
        const links = page.locator('aside a[href="#"]');
        const count = await links.count();
        for (let index = 0; index < count; index += 1) {
            const link = links.nth(index);
            const label = ((await link.innerText().catch(() => '')) || `#${index}`).trim().replace(/\s+/g, ' ');
            if (!(await link.isVisible().catch(() => false))) continue;
            await link.click({ timeout: 3000 }).catch(() => fail(`menu ${label}`, 'could not be clicked'));
            await page.waitForTimeout(250);
            const length = await page.evaluate(() => document.body.innerText.length);
            if (length < 200) fail(`menu ${label}`, 'page became nearly empty');
        }
    }

    await page.screenshot({ path: join(outDir, `konveksi-${viewport.name}.png`) });
    await context.close();
}

await browser.close();
server.close();
console.log(failures.length ? `\n${failures.length} problem(s).` : '\nKonveksi client booted and every menu opened without errors.');
process.exit(failures.length ? 1 : 0);
