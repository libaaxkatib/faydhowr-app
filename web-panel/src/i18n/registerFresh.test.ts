import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { buildRegister } from './registerBuild.ts';

const register = readFileSync(new URL('../../../docs/HR_SOMALI_TRANSLATION_REGISTER.md', import.meta.url), 'utf8').replace(/\r\n/g, '\n');

test('the register is generated from the catalogue and is up to date (run `npm run i18n:register` after changing a message)', () => {
  assert.equal(register.trimEnd(), buildRegister().trimEnd());
});

test('the register lists every locked message with its exact Somali and marks it locked', () => {
  const baseline = JSON.parse(readFileSync(new URL('./baseline/approved-161.json', import.meta.url), 'utf8').replace(/\r\n/g, '\n')) as { messages: { key: string; id: string; so: string }[] };
  const missing = baseline.messages.filter((m) => !register.includes(`| \`${m.key}\` | ${m.id} | locked |`) || !register.includes(` | ${m.so.replace(/\|/g, '\\|')} | approved |`));
  assert.deepEqual(missing.map((m) => m.key), []);
  assert.equal([...register.matchAll(/\| locked \|/g)].length, 161);
});
