// `npm run i18n:scan [-- path ...]` lists user-visible text that does not go through the catalogue (see untranslatedScan.ts). Paths are relative to src/;
// with no path the whole panel is scanned. Exit code 1 when anything is found that is not in untranslated-allowlist.json.

import { readFileSync, readdirSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

import { scanSource, unallowed, type AllowEntry, type Finding } from './untranslatedScan.ts';

const SRC = fileURLToPath(new URL('../', import.meta.url));

function files(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) return entry.name === 'i18n' ? [] : files(path);

    return /\.(ts|tsx)$/.test(entry.name) && !/\.test\.ts$/.test(entry.name) ? [path] : [];
  });
}

const targets = process.argv.slice(2);
const all = files(SRC).filter((path) => targets.length === 0 || targets.some((t) => relative(SRC, path).replace(/\\/g, '/').startsWith(t.replace(/\\/g, '/'))));
const allow = JSON.parse(readFileSync(new URL('./untranslated-allowlist.json', import.meta.url), 'utf8')) as AllowEntry[];
const found: Finding[] = all.flatMap((path) => scanSource(relative(SRC, path).replace(/\\/g, '/'), readFileSync(path, 'utf8')));
const remaining = unallowed(found, allow);

for (const f of remaining) console.log(`${f.file}:${f.line}  [${f.kind}]  ${f.text.slice(0, 140)}`);
console.log(`\n${remaining.length} untranslated item(s) in ${new Set(remaining.map((f) => f.file)).size} file(s) (${found.length - remaining.length} allowlisted, ${all.length} files scanned).`);
process.exit(remaining.length === 0 ? 0 : 1);
