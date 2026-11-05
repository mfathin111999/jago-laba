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

test('the generated Tailwind utility CSS exists and covers the utility classes used in templates', () => {
    // Templates may use Tailwind utilities (as the legacy pages did); they only render if `npm run css:tailwind` has been run.
    const generated = readFileSync(join(root, '..', 'css', 'legacy', 'tailwind.generated.css'), 'utf8');
    const used = new Set<string>();
    files.forEach(({ source }) => {
        for (const match of template(source).matchAll(/class="([^"]*)"/g)) {
            match[1].split(/s+/).filter((name) => /^(?:min-h-screen|rounded-(?:lg|xl|2xl|full)|(?:p|px|py|m|mt|mb)-d+|gap-d+|font-(?:black|bold|extrabold)|space-y-d+)$/.test(name)).forEach((name) => used.add(name));
        }
    });
    const missing = [...used].filter((name) => !generated.includes(`.${name}`));

    assert.deepEqual(missing, [], `run npm run css:tailwind: missing ${missing.join(', ')}`);
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
