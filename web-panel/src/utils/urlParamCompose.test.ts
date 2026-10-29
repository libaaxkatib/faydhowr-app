import test from 'node:test';
import assert from 'node:assert/strict';

import { applyChange, composeBase, type PendingChange } from './urlParamCompose.ts';
import { withParam, withoutFilters } from './urlFilters.ts';
import { withPreset, withTypedDate } from './dateRangePreset.ts';

// The bug (2026-10-29): a handler that changed a filter and then called setPage(1) lost the filter, because both changes started from the same stale
// parameters. These tests replay that handler sequence through the same compose step the hook uses.
const WEDNESDAY = new Date(2026, 9, 28, 14, 30);

/** Runs several changes "in one event": nothing renders between them, so the shown parameters stay the same. */
function inOneEvent(shown: string, ...changes: Array<(previous: URLSearchParams) => URLSearchParams>): URLSearchParams {
  const start = new URLSearchParams(shown);
  let pending: PendingChange | null = null;
  for (const change of changes) pending = applyChange(start, pending, change);

  return (pending as PendingChange | null)!.next;
}

test('a search followed by "back to page 1" keeps the search (the Customers / Former / Waiting / Admin Users handlers)', () => {
  const next = inOneEvent('page=3', (p) => withParam(p, 'q', 'abc'), (p) => withParam(p, 'page', ''));
  assert.deepEqual(Object.fromEntries(next), { q: 'abc' });
});

test('without composing, the second change would have overwritten the first (documents the failure being guarded)', () => {
  const stale = new URLSearchParams('page=3');
  const lastWins = withParam(stale, 'page', ''); // what react-router did: every updater started from `stale`
  assert.equal(lastWins.get('q'), null);
});

test('several filters changed in one event all survive and the page is dropped', () => {
  const next = inOneEvent('q=x&page=4', (p) => withParam(p, 'status', 'ACTIVE'), (p) => withParam(p, 'role', 'agent'), (p) => withParam(p, 'page', ''));
  assert.deepEqual(Object.fromEntries(next), { q: 'x', status: 'ACTIVE', role: 'agent' });
});

test('a preset followed by "back to page 1" keeps the date range (the report pages)', () => {
  const next = inOneEvent('department=3&page=2', (p) => withPreset(p, 'this_month', WEDNESDAY), (p) => withParam(p, 'page', ''));
  assert.deepEqual(Object.fromEntries(next), { department: '3', preset: 'this_month', from: '2026-10-01', to: '2026-10-28' });
});

test('a typed date followed by "back to page 1" keeps the date and turns the preset into custom', () => {
  const next = inOneEvent('preset=this_month&from=2026-10-01&to=2026-10-28', (p) => withTypedDate(p, 'from', '2026-10-10'), (p) => withParam(p, 'page', ''));
  assert.deepEqual(Object.fromEntries(next), { preset: 'custom', from: '2026-10-10', to: '2026-10-28' });
});

test('reset in the same event as a change leaves the filters cleared and keeps unrelated parameters', () => {
  const next = inOneEvent('tab=open&q=x&status=A', (p) => withParam(p, 'q', 'y'), (p) => withoutFilters(p, ['q', 'status']));
  assert.deepEqual(Object.fromEntries(next), { tab: 'open' });
});

test('once the router has rendered the new address the next event starts from it, not from the old pending change', () => {
  const shown = new URLSearchParams('q=abc');
  const stalePending: PendingChange = { from: 'page=3', next: new URLSearchParams('q=abc') };
  assert.equal(composeBase(shown, stalePending), shown, 'a pending change made from another address is ignored');
  assert.equal(composeBase(shown, null), shown);
  // Back to an address seen before: the pending change recorded from that address must not resurface once it has been cleared after rendering
  const back = applyChange(new URLSearchParams('q=abc'), null, (p) => withParam(p, 'q', 'abcd'));
  assert.equal(back.next.get('q'), 'abcd');
});
