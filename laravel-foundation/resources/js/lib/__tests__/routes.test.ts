import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';
import { allRoutePaths } from '../routes.ts';

type RouteRow = { methods: string[]; uri: string; name: string | null };

const snapshot = JSON.parse(readFileSync(join(import.meta.dirname, '..', '..', '..', '..', 'tests', 'Feature', 'fixtures', 'routes.snapshot.json'), 'utf8')) as RouteRow[];
const known = new Set(snapshot.map((route) => `/${route.uri}`));

test('every path in lib/routes.ts exists in the backend route contract', () => {
    const missing = allRoutePaths().filter((path) => !known.has(path));

    assert.deepEqual(missing, [], `not in tests/Feature/fixtures/routes.snapshot.json: ${missing.join(', ')}`);
});

test('the paths are unique, absolute and carry no query string', () => {
    const paths = allRoutePaths();

    assert.equal(new Set(paths).size, paths.length, 'a path is listed twice');
    for (const path of paths) {
        assert.ok(path.startsWith('/'), path);
        assert.ok(!path.includes('?') && !path.includes('#'), `${path} must not carry a query string`);
    }
    assert.ok(paths.length >= 30);
});
