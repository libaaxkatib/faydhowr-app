import test from 'node:test';
import assert from 'node:assert/strict';

import { pageFromParam, returnState, withParam } from './urlFilters.ts';

test('a page number from the address bar is a positive whole number, otherwise page 1', () => {
  assert.equal(pageFromParam('4'), 4);
  assert.equal(pageFromParam(null), 1);
  assert.equal(pageFromParam('0'), 1);
  assert.equal(pageFromParam('-3'), 1);
  assert.equal(pageFromParam('2.5'), 1);
  assert.equal(pageFromParam('abc'), 1);
});

test('changing a filter keeps the others and returns to page 1; changing the page keeps everything', () => {
  const start = new URLSearchParams('q=amina&status=active&page=7');
  assert.equal(withParam(start, 'status', 'waiting').toString(), 'q=amina&status=waiting');
  assert.equal(withParam(start, 'q', '').toString(), 'status=active');
  assert.equal(withParam(start, 'page', '8').toString(), 'q=amina&status=active&page=8');
  assert.equal(withParam(start, 'page', '').toString(), 'q=amina&status=active');
  assert.equal(start.toString(), 'q=amina&status=active&page=7', 'the original is never mutated');
});

test('the return state carries the list exactly as it was left', () => {
  assert.deepEqual(returnState('/hr/former-employees', '?q=ali&page=3', 'Former Employees'), { fromPath: '/hr/former-employees?q=ali&page=3', fromLabel: 'Former Employees' });
  assert.deepEqual(returnState('/hr/waiting', '', 'Waiting'), { fromPath: '/hr/waiting', fromLabel: 'Waiting' });
});
