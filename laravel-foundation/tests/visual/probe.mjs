/** Dev helper: lists the elements that stick out past the viewport on one fixture page. Usage: PAGE=marketing WIDTH=1366 node tests/visual/probe.mjs */
import { createServer } from 'node:http';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { extname, join, normalize, resolve } from 'node:path';
import { chromium } from 'playwright';

const root = resolve(import.meta.dirname, '..', '..');
const buildDir = join(root, 'public', 'build');
const manifest = JSON.parse(readFileSync(join(buildDir, 'manifest.json'), 'utf8'));
const entry = manifest['resources/js/app.ts'];
const css = manifest['resources/css/app.css'];
const name = process.env.PAGE ?? 'dashboard';
const fixture = JSON.parse(readFileSync((name.includes('/') ? name : join(import.meta.dirname, 'fixtures', `${name}.json`)), 'utf8'));
const types = { '.js': 'text/javascript', '.css': 'text/css' };

const server = createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname.startsWith('/build/')) {
        const file = normalize(join(buildDir, url.pathname.slice(7)));
        if (existsSync(file) && statSync(file).isFile()) {
            response.writeHead(200, { 'Content-Type': types[extname(file)] ?? 'application/octet-stream' });
            response.end(readFileSync(file));

            return;
        }
    }
    response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    response.end(`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/build/${css.file}"><script type="module" src="/build/${entry.file}"></script></head><body><script data-page="app" type="application/json">${JSON.stringify(fixture).replace(/</g, '<')}</script><div id="app"></div></body></html>`);
});
await new Promise((done) => server.listen(0, '127.0.0.1', done));
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: Number(process.env.WIDTH ?? 1366), height: 900 } });
if (process.env.SHOT) await page.setViewportSize({ width: Number(process.env.WIDTH ?? 1366), height: 900 });
await page.goto(`http://127.0.0.1:${server.address().port}${fixture.url}`, { waitUntil: 'networkidle' });
if (process.env.SHOT) await page.screenshot({ path: process.env.SHOT, fullPage: true });
const result = await page.evaluate(() => {
    const limit = window.innerWidth;
    const out = [];
    for (const el of document.querySelectorAll('body *')) {
        const rect = el.getBoundingClientRect();
        if (rect.right > limit + 1 && getComputedStyle(el).display !== 'none') {
            const overflow = el.closest('[class*="overflow-"], .table-wrap, .mp-order-table-wrap');
            out.push(`${el.tagName.toLowerCase()}.${String(el.className).slice(0, 80)} right=${Math.round(rect.right)} w=${Math.round(rect.width)} inScroller=${Boolean(overflow && overflow !== el)}`);
        }
    }

    return out.slice(0, 25);
});
console.log(result.join('\n'));
await browser.close();
server.close();
