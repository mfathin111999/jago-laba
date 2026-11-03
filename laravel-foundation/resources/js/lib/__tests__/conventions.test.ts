import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { test } from 'node:test';

const root = join(import.meta.dirname, '..', '..');

function vueFiles(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        return statSync(path).isDirectory() ? vueFiles(path) : path.endsWith('.vue') ? [path] : [];
    });
}

const files = vueFiles(root).map((path) => ({ name: relative(root, path).split(sep).join('/'), source: readFileSync(path, 'utf8') }));
const template = (source: string) => source.slice(source.indexOf('<template>'));

test('there are Vue files to check', () => {
    assert.ok(files.length >= 10);
});

test('templates do not use Tailwind utility classes, because the project ships no Tailwind CSS', () => {
    // These classes would silently render unstyled.
    const utility = /class="[^"]*\b(min-h-screen|bg-(?:slate|orange|emerald|amber|red)-\d+|text-(?:slate|orange|emerald|amber|red)-\d+|rounded-(?:lg|xl|2xl|full)|(?:p|px|py|m|mt|mb)-\d+|space-y-\d+|gap-\d+|font-(?:black|bold)|max-w-\w+|md:\w+|sm:\w+|lg:\w+)\b/;
    const offenders = files.filter(({ source }) => utility.test(template(source))).map(({ name }) => name);

    assert.deepEqual(offenders, [], `use the classes from resources/css/app.css instead: ${offenders.join(', ')}`);
});

test('templates do not reach for window or document, which Vue does not expose to templates', () => {
    const offenders = files.filter(({ source }) => /\b(window|document)\./.test(template(source))).map(({ name }) => name);

    assert.deepEqual(offenders, [], `move browser globals into <script setup>: ${offenders.join(', ')}`);
});

test('mutating requests go through the shared API client, so a refusal can never look like success', () => {
    const offenders = files
        .filter(({ name }) => !name.startsWith('Pages/Foundation'))
        .filter(({ source }) => /fetch\(\s*[`'"][^`'"]+[`'"]\s*,\s*\{[^}]*method:\s*['"](POST|PUT|PATCH|DELETE)['"]/.test(source))
        .map(({ name }) => name);

    assert.deepEqual(offenders, [], `use apiRequest from lib/api.ts: ${offenders.join(', ')}`);
});
