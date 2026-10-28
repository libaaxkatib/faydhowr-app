import test from 'node:test';
import assert from 'node:assert/strict';

import { withCallerWidth } from './selectClasses.ts';

const BASE = 'h-10 w-full rounded-sm border border-border bg-surface px-3 text-sm';

test('a select given its own width loses w-full, one without keeps it', () => {
  assert.ok(!/\bw-full\b/.test(withCallerWidth(BASE, 'w-44')));
  assert.ok(!/\bw-full\b/.test(withCallerWidth(BASE, 'mt-2 w-[12rem]')));
  assert.equal(withCallerWidth(BASE, ''), BASE);
  assert.equal(withCallerWidth(BASE, 'max-w-xs'), BASE, 'max-w is not a width');
  assert.equal(withCallerWidth(BASE, 'min-w-0'), BASE);
  assert.ok(withCallerWidth(BASE, 'w-44').includes('h-10 ') && withCallerWidth(BASE, 'w-44').includes('rounded-sm'), 'the other classes stay');
});
