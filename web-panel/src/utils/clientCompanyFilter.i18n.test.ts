import test from 'node:test';
import assert from 'node:assert/strict';

import { CATALOGUE } from '../i18n/messages/index.ts';
import { CATEGORY_FILTERS } from './clientCompanyFilter.ts';

test('every category filter keeps its English label and points at a message with the same English (or the approved Company wording)', () => {
  for (const filter of CATEGORY_FILTERS) assert.equal(CATALOGUE[filter.labelKey].en, filter.label);
  assert.equal(CATALOGUE[CATEGORY_FILTERS[1].labelKey].so, 'Shirkad');
});
