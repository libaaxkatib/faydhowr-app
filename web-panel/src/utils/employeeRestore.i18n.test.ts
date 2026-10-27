import test from 'node:test';
import assert from 'node:assert/strict';

import { setActiveLanguage } from '../i18n/active.ts';
import { restoreLanding } from './employeeRestore.ts';

test('the list a restored person reappears in is named in the active language, with the same path', () => {
  try {
    setActiveLanguage('so');
    assert.deepEqual(restoreLanding({ status: 'waiting', pipeline_stage: null }), { label: 'Shaqa sugayaal', path: '/hr/waiting' });
    assert.deepEqual(restoreLanding({ status: 'applicant', pipeline_stage: 'need_training' }), { label: 'Ubaahan Tababar', path: '/hr/pipeline/training' });
    assert.deepEqual(restoreLanding({ status: 'active', pipeline_stage: null }), { label: 'Shaqaalaha', path: '/hr/employees' });
  } finally {
    setActiveLanguage('en');
  }
  assert.deepEqual(restoreLanding({ status: 'waiting', pipeline_stage: null }), { label: 'Waiting', path: '/hr/waiting' });
});
