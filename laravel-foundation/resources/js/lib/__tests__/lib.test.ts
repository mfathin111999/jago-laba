import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ApiError, apiRequest, errorMessage, newIdempotencyKey } from '../api.ts';
import { UNKNOWN, formatDate, formatRupiah, formatText, formatValue, marginPercent } from '../format.ts';

test('unknown figures are never shown as zero', () => {
    for (const value of [null, undefined, '', 'abc', NaN, Infinity, true]) {
        assert.equal(formatValue(value), UNKNOWN, String(value));
    }
    assert.equal(formatValue(0), 'Rp 0', 'a real zero is still a zero');
    assert.equal(formatValue('0'), 'Rp 0');
});

test('currency, counts and percentages use Indonesian grouping', () => {
    assert.equal(formatRupiah(1234567), 'Rp 1.234.567');
    assert.equal(formatRupiah(-5000), '-Rp 5.000');
    assert.equal(formatValue(1500.6, 'count'), '1.501');
    assert.equal(formatValue(12.345, 'percent'), '12,3%');
    assert.equal(formatValue(2.5, 'decimal'), '2,5');
});

test('text and dates fall back to the unknown label', () => {
    assert.equal(formatText(null), UNKNOWN);
    assert.equal(formatText('   '), UNKNOWN);
    assert.equal(formatText(' Toko A '), 'Toko A');
    assert.equal(formatDate('2026-11-04 10:30:00'), '4 Nov 2026');
    assert.equal(formatDate('2026-12-25T00:00:00+07:00'), '25 Des 2026');
    assert.equal(formatDate('2026-13-01'), UNKNOWN);
    assert.equal(formatDate(''), UNKNOWN);
    assert.equal(formatDate(20261104), UNKNOWN);
});

test('margin is unknown unless both profit and sales are known', () => {
    assert.equal(marginPercent(25, 100), 25);
    assert.equal(marginPercent(null, 100), null);
    assert.equal(marginPercent(10, 0), null);
    assert.equal(marginPercent(10, undefined), null);
});

const reply = (status: number, body: unknown) => async () => new Response(typeof body === 'string' ? body : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

test('a successful response is returned and the request carries the CSRF header and JSON body', async () => {
    let seen: { url: string; init: RequestInit } | null = null;
    const fetcher = (async (url: string, init: RequestInit) => {
        seen = { url, init };
        return new Response(JSON.stringify({ success: true, data: { id: 1 } }), { status: 200 });
    }) as unknown as typeof fetch;

    const result = await apiRequest('/x', { a: 1 }, { csrf: 'tok', fetcher, headers: { 'X-Idempotency-Key': 'k' } });

    assert.equal(result.data?.id, 1);
    const headers = seen!.init.headers as Record<string, string>;
    assert.equal(headers['X-CSRF-TOKEN'], 'tok');
    assert.equal(headers['X-Idempotency-Key'], 'k');
    assert.equal(headers['Content-Type'], 'application/json');
    assert.equal(seen!.init.body, '{"a":1}');
    assert.equal(seen!.init.method, 'POST');
});

test('a refusal is never treated as success, whatever shape the server uses', async () => {
    const cases: Array<[number, unknown, string, string | null]> = [
        [200, { success: false, message: 'Nama wajib diisi.' }, 'Nama wajib diisi.', null],
        [400, { ok: false, error: { message: 'Stok tidak mencukupi', code: 'OPERATION_FAILED' } }, 'Stok tidak mencukupi', 'OPERATION_FAILED'],
        [503, { success: false, message: 'Konektor belum aktif.', error_code: 'PROVIDER_CONNECTOR_INACTIVE' }, 'Konektor belum aktif.', 'PROVIDER_CONNECTOR_INACTIVE'],
        [419, '{}', 'Token keamanan kedaluwarsa. Muat ulang halaman.', null],
        [500, 'not json', 'Permintaan belum dapat diproses (HTTP 500).', null],
    ];
    for (const [status, body, message, code] of cases) {
        await assert.rejects(
            () => apiRequest('/x', {}, { fetcher: reply(status, body) as unknown as typeof fetch }),
            (error: unknown) => error instanceof ApiError && error.message === message && error.status === status && error.code === code,
            `${status} ${JSON.stringify(body)}`,
        );
    }
});

test('a network failure becomes a readable error', async () => {
    const fetcher = (async () => {
        throw new TypeError('Failed to fetch');
    }) as unknown as typeof fetch;

    await assert.rejects(() => apiRequest('/x', {}, { fetcher }), (error: unknown) => error instanceof ApiError && error.status === 0 && /menghubungi server/.test(error.message));
});

test('error messages fall back to a status based text and idempotency keys are unique', () => {
    assert.match(errorMessage(null, 401), /Sesi login berakhir/);
    assert.match(errorMessage({ message: '  ' }, 422), /belum valid/);
    assert.notEqual(newIdempotencyKey(), newIdempotencyKey());
    assert.ok(newIdempotencyKey().length >= 16, 'long enough for the Konveksi gate (16+)');
});
