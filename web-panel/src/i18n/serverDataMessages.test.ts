import test from 'node:test';
import assert from 'node:assert/strict';

import { CATALOGUE } from './messages/index.ts';
import { localizeServerMessage } from './serverMessages.ts';

// The sentences the server puts in data fields (not errors) that the panel shows in Somali. That each is word for word in the backend source is checked
// with the other server sentences in serverMessages.test.ts.
const SOURCES: [string, keyof typeof CATALOGUE][] = [
  ['payroll estimate note', 'notify.server.payrollEstimateNote'],
  ['multi-company policy label', 'notify.server.multiCompanyPolicyLabel'],
  ['multi-company policy description', 'notify.server.multiCompanyPolicyDescription'],
];

test('Somali shows the Somali sentence; English and unknown text are returned untouched', () => {
  for (const [, key] of SOURCES) {
    assert.equal(localizeServerMessage('so', CATALOGUE[key].en), CATALOGUE[key].so);
    assert.equal(localizeServerMessage('en', CATALOGUE[key].en), CATALOGUE[key].en);
  }
  assert.equal(localizeServerMessage('so', 'Some sentence the catalogue does not know.'), 'Some sentence the catalogue does not know.');
});
