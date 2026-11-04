/**
 * Visual smoke test: serves the real production build (public/build) with recorded page props
 * (tests/visual/fixtures) and opens every Marketplace page at desktop and mobile width.
 * It needs no database. It fails on console errors, horizontal overflow, empty pages and
 * "undefined" / "NaN" / "[object Object]" leaking into the text.
 *
 * Usage: npm run build && npm run test:visual   (screenshots land in tests/visual/out)
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

/** Path (without query) → fixture name. */
const PAGES = {
    '/pilot/marketplace': 'dashboard',
    '/pilot/marketplace/orders': 'orders',
    '/pilot/marketplace/analytics': 'analytics',
    '/marketplace/catalog': 'catalog',
    '/marketplace/stock': 'stock',
    '/marketplace/marketing': 'marketing',
    '/marketplace/income': 'income',
    '/marketplace/expenses': 'expenses',
    '/marketplace/integrations': 'integrations',
    '/marketplace/sync': 'sync',
    '/marketplace/stores': 'stores',
};
const VIEWPORTS = [
    { name: 'desktop', width: 1366, height: 900 },
    { name: 'mobile', width: 390, height: 844 },
];
const TYPES = { '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.json': 'application/json' };

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
    const fixture = PAGES[url.pathname];
    if (!fixture) {
        response.writeHead(404, { 'Content-Type': 'application/json' });
        response.end('{"success":false,"message":"not in harness"}');

        return;
    }
    const page = { ...JSON.parse(readFileSync(join(fixtureDir, `${fixture}.json`), 'utf8')), url: url.pathname + url.search };
    if (request.headers['x-inertia']) {
        response.writeHead(200, { 'Content-Type': 'application/json', 'X-Inertia': 'true', Vary: 'X-Inertia' });
        response.end(JSON.stringify(page));

        return;
    }
    response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    response.end(`<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="harness"><title>harness</title><link rel="stylesheet" href="/build/${css.file}"><script type="module" src="/build/${entry.file}"></script></head><body><script data-page="app" type="application/json">${JSON.stringify(page).replace(/</g, '\u003C')}</script><div id="app"></div></body></html>`);
});

await new Promise((done) => server.listen(0, '127.0.0.1', done));
const base = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch();
const failures = [];

for (const viewport of VIEWPORTS) {
    const context = await browser.newContext({ viewport: { width: viewport.width, height: viewport.height }, locale: 'id-ID', timezoneId: 'Asia/Jakarta' });
    for (const [path, fixture] of Object.entries(PAGES)) {
        const tab = await context.newPage();
        const problems = [];
        tab.on('console', (message) => message.type() === 'error' && problems.push(`console: ${message.text()}`));
        tab.on('pageerror', (error) => problems.push(`pageerror: ${error.message}`));
        tab.on('requestfailed', (req) => problems.push(`request failed: ${req.url()}`));
        const fixturePath = JSON.parse(readFileSync(join(fixtureDir, `${fixture}.json`), 'utf8')).url;
        await tab.goto(base + fixturePath, { waitUntil: 'networkidle' });
        await tab.waitForSelector('h1', { timeout: 5000 }).catch(() => problems.push('no <h1> rendered'));

        const report = await tab.evaluate(() => ({
            overflow: document.documentElement.scrollWidth - window.innerWidth,
            text: document.body.innerText,
            nav: [...document.querySelectorAll('.sidebar-nav a')].map((a) => a.getAttribute('href')),
            wide: [...document.querySelectorAll('body *')].filter((el) => el.getBoundingClientRect().right > window.innerWidth + 1 && !el.closest('.table-wrap, .sidebar-nav') && getComputedStyle(el).display !== 'none').slice(0, 4).map((el) => `${el.tagName.toLowerCase()}.${String(el.className).split(' ').join('.')}(${Math.round(el.getBoundingClientRect().right)})`),
        }));
        if (report.overflow > 1) problems.push(`horizontal overflow of ${report.overflow}px (widest: ${report.wide.join(', ') || 'unknown'})`);
        for (const bad of ['undefined', 'NaN', '[object Object]']) {
            if (report.text.includes(bad)) problems.push(`text contains "${bad}"`);
        }
        if (report.text.trim().length < 40) problems.push('page is nearly empty');
        if (report.nav.length === 0 && !path.includes('analytics')) problems.push('no sidebar navigation');

        if (process.env.CHAIN && report.overflow > 1) {
            console.log(await tab.evaluate(() => { const tw = document.querySelector('.table-wrap'); const out = [tw ? `table-wrap overflowX=${getComputedStyle(tw).overflowX} w=${Math.round(tw.getBoundingClientRect().width)} sw=${tw.scrollWidth}` : 'no table-wrap']; let el = document.querySelector('.panel'); while (el) { const cs = getComputedStyle(el); out.push(`${el.tagName.toLowerCase()}.${el.className} w=${Math.round(el.getBoundingClientRect().width)} display=${cs.display}`); el = el.parentElement; } return out.join('\n'); }));
        }
        await tab.screenshot({ path: join(outDir, `${fixture}-${viewport.name}.png`), fullPage: true });
        console.log(`${problems.length ? 'FAIL' : 'ok  '} ${viewport.name.padEnd(7)} ${path}${problems.length ? `\n       - ${problems.join('\n       - ')}` : ''}`);
        problems.forEach((problem) => failures.push(`${viewport.name} ${path}: ${problem}`));
        await tab.close();
    }
    await context.close();
}

await browser.close();
server.close();
console.log(failures.length ? `\n${failures.length} problem(s).` : '\nAll pages rendered cleanly.');
process.exit(failures.length ? 1 : 0);
