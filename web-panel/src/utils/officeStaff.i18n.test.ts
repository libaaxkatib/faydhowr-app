import test from 'node:test';
import assert from 'node:assert/strict';

import { setActiveLanguage } from '../i18n/active.ts';
import { documentFileError, documentGroupLabel, documentSummary, phoneError, salaryError } from './officeStaff.ts';

test('office-staff validation and summary text follow the active language', () => {
  try {
    setActiveLanguage('so');
    assert.equal(salaryError(''), 'Geli mushaharka bille.');
    assert.equal(phoneError('12'), 'Geli lambar taleefan oo sax ah (ugu yaraan 6 lambar).');
    assert.equal(documentFileError({ name: 'a.exe', size: 5 }), '"a.exe" ma aha nooc fayl oo la ogol yahay (la ogol yahay: jpg, jpeg, png, gif, webp, pdf, doc, docx, xls, xlsx, txt).');
    assert.equal(documentFileError({ name: 'a.pdf', size: 0 }), '"a.pdf" waa madhan yahay.');
    assert.equal(
      documentSummary([
        { key: 'identity', label: 'Identification document', category: 'Identity Documents', required: true, status: 'on_file', files: [] },
        { key: 'other', label: 'Other supporting documents', category: 'Other HR Documents', required: false, status: 'missing', files: [] },
      ]),
      '1 ka mid ah 1 dukumenti oo loo baahan yahay ayaa diiwaan ku jira',
    );
  } finally {
    setActiveLanguage('en');
  }
  assert.equal(salaryError(''), 'Enter the monthly salary.');
});

test('document group labels follow the active language and keep the given label in English', () => {
  const group = { key: 'employment' as const, label: 'Employment / appointment document' };
  try {
    setActiveLanguage('so');
    assert.equal(documentGroupLabel(group), 'Dukumentiga shaqaaleynta / magacaabista');
    assert.equal(documentGroupLabel({ key: 'identity', label: 'Identification document' }), 'Dukumentiga aqoonsiga');
  } finally {
    setActiveLanguage('en');
  }
  assert.equal(documentGroupLabel(group), 'Employment / appointment document');
  assert.equal(documentGroupLabel({ key: 'other', label: 'Server label' }), 'Server label');
});
