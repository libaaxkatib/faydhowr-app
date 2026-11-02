import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { CATALOGUE } from '../../../i18n/messages/index.ts';
import { blockerTitleKey } from '../../../i18n/messages/recovery.ts';

const SRC = fileURLToPath(new URL('../../../', import.meta.url));
const read = (path: string) => readFileSync(join(SRC, path), 'utf8');

test('the Recovery Center page is reachable only by Super Admin (route and navigation link)', () => {
  const app = read('App.tsx');
  const nav = read('layouts/nav.config.ts');

  assert.match(app, /path="\/system\/recovery" element=\{<RequireSuperAdmin><RecoveryCenterPage \/><\/RequireSuperAdmin>\}/);
  assert.match(nav, /to: '\/system\/recovery'[^}]*superAdminOnly: true/);
  assert.doesNotMatch(nav, /to: '\/system\/recovery'[^}]*permission:/, 'it must not be opened by a permission');
});

test('the API client calls exactly the routes the server defines, and only apply writes', () => {
  const api = read('api/recovery.ts');
  const routes = readFileSync(join(SRC, '../../backend/routes/api.php'), 'utf8');

  for (const path of ['workflows', 'assignments', 'payroll-preview', 'preview', 'apply', 'corrections', 'errors']) {
    assert.ok(api.includes(`admin/recovery/${path}`), `client calls admin/recovery/${path}`);
    assert.ok(routes.includes(`'${path}'`), `server defines ${path}`);
  }

  assert.equal((api.match(/method: 'POST'/g) ?? []).length, 2, 'only preview and apply are POST');
  assert.match(api, /confirm: true/, 'apply always sends the explicit confirmation');
  assert.match(api, /idempotency_key: input\.idempotencyKey/);
  assert.match(api, /token: input\.token/);
});

test('Apply is wired to the allowed preview only, with one idempotency key per preview, and never before the reason and confirmation', () => {
  const page = read('features/system/recovery/RecoveryCenterPage.tsx');

  assert.match(page, /idempotencyKey\.current = newIdempotencyKey\(\)/, 'a new key for every preview');
  assert.match(page, /token: preview!\.token!/);
  assert.match(page, /canApply=\{canApply\(preview, reason, confirmed\)\}/);
  assert.match(page, /useEffect\(\(\) => \{\s*invalidatePreview\(\);\s*\}, \[workflow, params\]\)/, 'changing the correction voids the preview');
  assert.match(page, /error\.code === 'PREVIEW_STALE' \|\| error\.code === 'PREVIEW_EXPIRED'/, 'a stale preview must be made again');
});

test('every workflow and blocker the server can report has a message in both languages', () => {
  for (const id of ['reopen_assignment', 'restore_cancelled_assignment', 'correct_assignment_dates', 'correct_absence']) {
    assert.ok(`option.recovery.wf.${id}` in CATALOGUE, id);
    assert.ok(`option.recovery.wf.${id}.help` in CATALOGUE, `${id} help`);
  }

  const engine = readFileSync(join(SRC, '../../backend/app/Support/Hr/Recovery/AssignmentRecovery.php'), 'utf8');
  const codes = new Set([...engine.matchAll(/\$this->b\('([A-Z_]+)'/g)].map((m) => m[1]));
  assert.ok(codes.size > 25);

  const untitled = [...codes].filter((code) => blockerTitleKey(code) === null);
  assert.deepEqual(untitled, [], 'each blocker code has a short, translated headline');
});

test('the Super Admin ledger event has a label in the cycle history', () => {
  assert.match(read('features/hrm/companyEmployees/HistoryDialog.tsx'), /window_corrected: 'option\.company\.cycleEvent\.window_corrected'/);
});
