/**
 * Operational checks that only mean something on a real MySQL/MariaDB: the store-deletion triggers, the permanent
 * deletion worker, mysqldump backups and the scheduler. It drives the running app (for the owner-only deletion API)
 * and artisan, and inspects the database with the mysql client.
 *
 *   . scripts/development/staging-env.sh   # in the shell that runs this, so artisan and the app share one database
 *   PHP_BIN=/path/to/php8.4 MYSQL_BIN=/path/to/mysql MYSQLDUMP_BIN=/path/to/mysqldump node tests/e2e/staging-ops-e2e.mjs
 *
 * It creates and deletes a throw-away store (STR-OPS) in the staging database.
 */
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { chromium } from 'playwright';

const base = process.env.E2E_BASE_URL || 'http://127.0.0.1:8800';
const php = process.env.PHP_BIN || 'php';
const mysql = process.env.MYSQL_BIN || 'mysql';
const dump = process.env.MYSQLDUMP_BIN || '';
const db = process.env.DB_DATABASE || 'jl_staging_a';
const port = process.env.DB_PORT || '3320';

const results = [];
const check = (name, ok, detail = '') => {
    results.push({ name, ok });
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${!ok && detail ? ` — ${detail}` : ''}`);
};
const sql = (statement, allowError = false) => {
    try {
        return execFileSync(mysql, ['-h127.0.0.1', `-P${port}`, '-uroot', '-N', '-B', db, '-e', statement], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
    } catch (error) {
        if (allowError) return `ERROR: ${String(error.stderr || error.message)}`;
        throw error;
    }
};
const artisan = (...args) => execFileSync(php, ['artisan', ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], env: process.env });

// ----- 1. triggers -----
artisan('marketplace:store-deletion', '--install');
const triggers = Number(sql(`SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='${db}' AND trigger_name LIKE 'jl\_erase\_%'`));
check('store deletion: write-barrier triggers are installed on MySQL', triggers > 0, `${triggers} triggers`);

// ----- 2. a throw-away store with related rows (a fresh id per run: the ledger row of an erased store blocks its id for good) -----
const OPS = `STR-OPS-${Date.now().toString(36).toUpperCase()}`;
sql(`INSERT INTO marketplace_stores (id, company_key, platform, store_name, store_identifier, status, authorization_status, created_at, updated_at) VALUES ('${OPS}','LOCAL-COMPANY','Shopee','Toko Uji Hapus','ops-ext-1','Terhubung','verified',NOW(),NOW())`);
sql(`INSERT INTO marketplace_expenses (store_id, description, vendor_name, expense_date, category, amount, payment_method, affects_profit, is_cashflow_only, created_at) VALUES ('${OPS}','biaya uji','v',CURDATE(),'Operasional',1000,'Transfer Bank',1,0,NOW())`);
sql(`INSERT INTO marketplace_orders (id, store_id, order_date, order_status, gross_sales, net_settlement_amount, total_cogs_hpp, internal_state, source_kind, transaction_detail_json) VALUES ('${OPS}-1','${OPS}',NOW(),'Selesai',1000,0,0,'active','api','{}')`);
check('store deletion: the throw-away store has data to erase', sql(`SELECT COUNT(*) FROM marketplace_orders WHERE store_id='${OPS}'`) === '1');

// ----- 3. request the deletion as the owner through the real API -----
const browser = await chromium.launch();
const page = await (await browser.newContext()).newPage();
await page.goto(base + '/', { waitUntil: 'networkidle' });
await page.fill('input[placeholder="Username"]', process.env.E2E_USER || 'owner');
await page.fill('input[placeholder="Password"]', process.env.E2E_PASSWORD || 'owner123');
await page.click('button[type="submit"]');
await page.waitForSelector('.workspace-marketplace');
await page.click('.workspace-marketplace');
await page.waitForURL('**/pilot/marketplace**');
const csrf = await page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.content || '');
const api = (body) => page.evaluate(async ([payload, token]) => {
    const response = await fetch('/api/marketplace-store-deletion.php', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify(payload) });
    return { status: response.status, body: await response.json() };
}, [body, csrf]);

const preview = await api({ action: 'preview', store_id: OPS });
check('store deletion: the preview lists what will be erased', preview.status === 200 && JSON.stringify(preview.body).includes('marketplace_orders'), JSON.stringify(preview.body).slice(0, 200));
const wrong = await api({ action: 'request', store_id: OPS, confirmation: 'nama salah' });
check('store deletion: a wrong confirmation name is refused and nothing is queued', wrong.status >= 400 && sql(`SELECT COUNT(*) FROM marketplace_store_deletions WHERE store_id='${OPS}'`) === '0', JSON.stringify(wrong.body).slice(0, 160));

// ----- 4. while the deletion is pending, the database itself refuses new rows for that store -----
const requested = await api({ action: 'request', store_id: OPS, confirmation: 'Toko Uji Hapus' });
check('store deletion: the owner can request it', requested.status === 200 || requested.status === 202, JSON.stringify(requested.body).slice(0, 200));
// ----- 5. the worker finishes it -----
artisan('marketplace:store-deletion', '--pending');
check('store deletion: the worker completed it', sql(`SELECT status FROM marketplace_store_deletions WHERE store_id='${OPS}'`) === 'completed', sql(`SELECT status, error_code FROM marketplace_store_deletions WHERE store_id='${OPS}'`));
// The ledger row stays after completion, so the database keeps refusing writes for that store id for good.
const blocked = sql(`INSERT INTO marketplace_orders (id, store_id, order_date, order_status, gross_sales, net_settlement_amount, total_cogs_hpp, internal_state, source_kind, transaction_detail_json) VALUES ('${OPS}-2','${OPS}',NOW(),'Selesai',1,0,0,'active','api','{}')`, true);
check('store deletion: a trigger rejects new orders for an erased store (STORE_PERMANENTLY_DELETING)', /STORE_PERMANENTLY_DELETING/.test(blocked) && sql(`SELECT COUNT(*) FROM marketplace_orders WHERE id='${OPS}-2'`) === '0', blocked.slice(0, 200));
check('store deletion: the store and all its rows are gone', ['marketplace_stores WHERE id', 'marketplace_orders WHERE store_id', 'marketplace_expenses WHERE store_id'].every((from) => sql(`SELECT COUNT(*) FROM ${from}='${OPS}'`) === '0'));
check('store deletion: other stores are untouched', Number(sql(`SELECT COUNT(*) FROM marketplace_orders WHERE store_id='STR-TT'`)) >= 28);

// ----- 6. mysqldump backup -----
if (dump) {
    const output = execFileSync(php, ['tests/e2e/helpers/backup-now.php'], { encoding: 'utf8', env: { ...process.env, JL_MYSQLDUMP: dump } });
    const result = JSON.parse(output.slice(output.indexOf('{')));
    check('backup: mysqldump produced a SQL file', result.status === 'success' && result.sql_file && existsSync(result.sql_file) && statSync(result.sql_file).size > 10000, JSON.stringify(result).slice(0, 240));
    const head = result.sql_file ? readFileSync(result.sql_file, 'utf8').slice(0, 4000) : '';
    check('backup: the SQL file is a real dump with schema', /CREATE TABLE/.test(head) || /CREATE TABLE/.test(result.sql_file ? readFileSync(result.sql_file, 'utf8') : ''));
}

// ----- 7. scheduler -----
const list = artisan('schedule:list');
check('scheduler: the four jobs are registered', ['marketplace:sync', 'marketplace:auto-sync', 'marketplace:store-deletion', 'konveksi:pending-snapshot'].every((job) => list.includes(job)), list.slice(0, 300));
const run = artisan('schedule:run').replace(/\u001b\[[0-9;]*m/g, '');
check('scheduler: a run executes the due jobs and reports them DONE', /Running \[.*\] \.+ .* DONE/.test(run) && !/FAIL|exception/.test(run), run.slice(0, 400));

// ----- 8. live sync: the legacy engine runs inside Laravel (fake provider, real MySQL) -----
const probe = JSON.parse(execFileSync(php, ['tests/e2e/helpers/live-sync-probe.php'], { encoding: 'utf8', env: process.env }).trim().split(/\r?\n/).pop());
check('live sync: products, orders, transactions and returns all succeeded', probe.jobs.length === 4 && probe.jobs.every((job) => job.status === 'succeeded'), JSON.stringify(probe.jobs));
check('live sync: the run finished and the connection records the successful sync', probe.pending === false && !!probe.last_successful_sync_at, JSON.stringify(probe));
check('live sync: the provider received the token decrypted from the legacy vault', String(probe.token_seen_by_provider).includes('token=probe-access-token'), String(probe.token_seen_by_provider));

await browser.close();
const failed = results.filter((result) => !result.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed.`);
process.exit(failed.length ? 1 : 0);
