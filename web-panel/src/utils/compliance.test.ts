import test from 'node:test';
import assert from 'node:assert/strict';

import { EMPTY_FILTERS, canSave, complianceQuery, confidenceTone, hasActiveFilters, isClosed, severityTone } from './compliance.ts';

test('only the filters that are set are sent, trimmed', () => {
  assert.deepEqual(complianceQuery(EMPTY_FILTERS), {});
  assert.deepEqual(complianceQuery({ ...EMPTY_FILTERS, search: '  Ali ', category: 'profile', responsible_admin_id: 'none' }), { search: 'Ali', category: 'profile', responsible_admin_id: 'none' });
  assert.equal(hasActiveFilters(EMPTY_FILTERS), false);
  assert.equal(hasActiveFilters({ ...EMPTY_FILTERS, status: 'open' }), true);
});

test('resolving a finding needs a note about the corrective action, other statuses do not', () => {
  assert.equal(canSave('open', '', null), true);
  assert.equal(canSave('in_progress', '', null), true);
  assert.equal(canSave('resolved', '', null), false);
  assert.equal(canSave('resolved', 'ab', null), false);
  assert.equal(canSave('resolved', 'Profile completed', null), true);
  assert.equal(canSave('resolved', '', 'Earlier note'), true, 'a note already on the finding counts');
});

test('needs-review findings are never styled as confirmed violations', () => {
  assert.equal(confidenceTone('confirmed'), 'danger');
  assert.equal(confidenceTone('needs_review'), 'info');
  assert.equal(severityTone('high'), 'danger');
  assert.equal(severityTone('medium'), 'warning');
  assert.equal(severityTone('low'), 'neutral');
  assert.equal(isClosed({ status: 'resolved' }), true);
  assert.equal(isClosed({ status: 'open' }), false);
});
