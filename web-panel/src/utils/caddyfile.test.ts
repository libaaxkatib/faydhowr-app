import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/**
 * web-panel/Caddyfile is the web server configuration of the panel: Railway builds web-panel/ with Railpack, which serves the built site with Caddy and
 * uses this file instead of its own default. CI cannot run Caddy, so these tests read the real file and pin what matters: the Railpack defaults it must
 * keep, and the cache rules it adds. (The behaviour itself was checked by running Caddy 2.11.7, the version Railway resolved, against a copy of the built site.)
 */

const caddyfile = readFileSync(new URL('../../Caddyfile', import.meta.url), 'utf8');
/** The file without comments, so a directive mentioned in a comment never counts. */
const code = caddyfile.replace(/^\s*#.*$/gm, '').replace(/\s+#.*$/gm, '');
const viteConfig = readFileSync(new URL('../../vite.config.ts', import.meta.url), 'utf8');

test('it keeps everything Railpack\'s default Caddyfile does: port, health route, security header, no Server header, compression, hidden dotfiles', () => {
  assert.match(code, /:\{\$PORT:80\}\s*\{/, 'listens on the port Railway assigns');
  assert.match(code, /auto_https off/, 'Railway terminates TLS');
  assert.match(code, /admin off/);
  assert.match(code, /trusted_proxies static private_ranges 100\.0\.0\.0\/8/);
  assert.match(code, /respond \/health 200/);
  assert.match(code, /X-Content-Type-Options "nosniff"/);
  assert.match(code, /^\s*-Server\s*$/m);
  assert.match(code, /encode\s*\{\s*gzip\s*zstd\s*\}/);
  assert.match(code, /hide \.git\s+hide \.env\*/);
});

test('it serves the folder Vite builds into and keeps the single-page-app fallback to /index.html', () => {
  assert.match(code, /root \* \/app\/dist\b/, 'Railpack copies the build output (dist) to /app/dist');
  assert.doesNotMatch(viteConfig, /outDir/, 'the build output folder is still the default, dist');
  assert.match(code, /try_files \{path\} \{path\}\.html \{path\}\/index\.html \/index\.html/);
});

test('hashed files in /assets/ that really exist are cached for a year and may not change (immutable)', () => {
  const block = code.match(/@hashed_asset\s*\{([^}]*)\}/);
  assert.ok(block, 'a matcher for the hashed assets');
  assert.match(block[1], /path \/assets\/\*/);
  assert.match(block[1], /^\s*file\s*$/m, 'only a file that exists: a missing asset falls back to the page and must not be pinned');
  assert.match(code, /header @hashed_asset Cache-Control "public, max-age=31536000, immutable"/);
});

test('everything else - index.html, page routes, a missing /assets/ URL - must be revalidated, so a new release is picked up at once', () => {
  const block = code.match(/@everything_else not\s*\{([^}]*)\}/);
  assert.ok(block, 'the opposite of the matcher above');
  assert.match(block[1], /path \/assets\/\*/);
  assert.match(block[1], /^\s*file\s*$/m, 'exactly the negation of @hashed_asset, so the two never overlap and never leave a gap');
  assert.match(code, /header @everything_else Cache-Control "no-cache"/);
});

test('there are exactly two Cache-Control rules, neither can hide the page or turn caching off for the hashed files', () => {
  assert.equal([...code.matchAll(/Cache-Control/g)].length, 2);
  assert.doesNotMatch(code, /no-store/, 'no-store would stop the 304 revalidation and re-download the page every time');
  const revalidate = code.match(/header @everything_else Cache-Control "([^"]*)"/);
  assert.ok(revalidate);
  assert.equal(revalidate[1], 'no-cache', 'exactly no-cache: stored, but checked with the server before every use (no max-age)');
  const hashed = code.match(/header @hashed_asset Cache-Control "([^"]*)"/);
  assert.ok(hashed);
  assert.deepEqual(hashed[1].split(/,\s*/), ['public', 'max-age=31536000', 'immutable']);
});

test('it is plain Caddyfile syntax: Railpack runs it through a Go template first, so it must contain no template markers', () => {
  assert.doesNotMatch(caddyfile, /\{\{/);
  assert.doesNotMatch(caddyfile, /\}\}/);
});
