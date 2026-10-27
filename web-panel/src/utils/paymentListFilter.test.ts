import test from 'node:test';
import assert from 'node:assert/strict';

import { buttonLabel, canDownload, downloadQuery, emptyOptionsMessage, fileName, optionsFor, selectedOption, type PaymentListFilterOptions } from './paymentListFilter.ts';

const options: PaymentListFilterOptions = {
  companies: [{ id: 3, name: 'Real Company' }, { id: 8, name: 'Another & Co.' }],
  guryo: [{ id: 5, name: 'Real Guri' }],
};

test('the selector lists the records of the chosen kind and nothing for All', () => {
  assert.deepEqual(optionsFor('company', options).map((o) => o.id), [3, 8]);
  assert.deepEqual(optionsFor('guri', options).map((o) => o.id), [5]);
  assert.deepEqual(optionsFor('all', options), []);
  assert.deepEqual(optionsFor('company', undefined), []);
});

test('All can always be downloaded; a company or guri needs a real record of that kind', () => {
  assert.equal(canDownload('all', '', options), true);
  assert.equal(canDownload('company', '', options), false, 'nothing chosen');
  assert.equal(canDownload('company', '3', options), true);
  assert.equal(canDownload('company', '5', options), false, 'a guri id is not a company');
  assert.equal(canDownload('guri', '5', options), true);
  assert.equal(canDownload('guri', '3', options), false);
});

test('the request names the record by its id and the scope, never by name', () => {
  assert.deepEqual(downloadQuery('2026-10', 'all', '3'), { period: '2026-10', scope: 'all' });
  assert.deepEqual(downloadQuery('2026-10', 'company', '3'), { period: '2026-10', scope: 'company', client_company_id: '3' });
  assert.deepEqual(downloadQuery('2026-10', 'guri', '5'), { period: '2026-10', scope: 'guri', client_company_id: '5' });
});

test('when no company or guri exists the selector says so', () => {
  assert.match(emptyOptionsMessage('company', { companies: [], guryo: [{ id: 1, name: 'G' }] }) ?? '', /No company/);
  assert.match(emptyOptionsMessage('guri', { companies: [{ id: 1, name: 'C' }], guryo: [] }) ?? '', /No guri/);
  assert.equal(emptyOptionsMessage('all', { companies: [], guryo: [] }), null);
  assert.equal(emptyOptionsMessage('company', options), null);
});

test('the button and the file name say which record the list is for', () => {
  const company = selectedOption('company', '8', options);
  assert.equal(company?.name, 'Another & Co.');
  assert.equal(buttonLabel('company', company), 'Download company list: Another & Co. (Excel)');
  assert.equal(buttonLabel('guri', selectedOption('guri', '5', options)), 'Download guri list: Real Guri (Excel)');
  assert.equal(buttonLabel('all', null), 'Download payment list (Excel)');
  assert.equal(fileName('2026-10', company), 'payment-list-2026-10-Another-Co.xlsx');
  assert.equal(fileName('2026-10', null), 'payment-list-2026-10.xlsx');
});
