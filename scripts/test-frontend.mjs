import { readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = fileURLToPath(new URL('../', import.meta.url));

function testFiles(directory) {
    return readdirSync(directory, { withFileTypes: true })
        .sort((a, b) => a.name.localeCompare(b.name))
        .flatMap(entry => {
            const path = resolve(directory, entry.name);
            if (entry.isDirectory()) return testFiles(path);
            return entry.isFile() && /\.test\.(mjs|ts)$/.test(entry.name) ? [path] : [];
        });
}

// Same suite and loader used by P4/R1. Standalone browser harnesses are separate.
const tests = testFiles(resolve(root, 'tests/frontend'));
if (tests.length === 0) throw new Error('No frontend test files found.');

const result = spawnSync(process.execPath, [
    '--experimental-strip-types',
    '--import', pathToFileURL(resolve(root, 'tests/frontend/v2/video/register-loader.mjs')).href,
    '--test', ...tests,
], { cwd: root, stdio: 'inherit' });

if (result.error) throw result.error;
process.exit(result.status ?? 1);
