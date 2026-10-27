import test from 'node:test';
import assert from 'node:assert/strict';

import { setActiveLanguage } from '../i18n/active.ts';
import { CATALOGUE } from '../i18n/messages/index.ts';
import { ACTIVATION_PATH_TEXT, BATCH_STATUS, checkImportFile, describeImportError, importTypeInfo, ROW_CODE_TEXT, ROW_STATUS, rowCodeText } from './hrImports.ts';

test('the import texts are English by default and Somali only while an HR screen shows Somali', () => {
  assert.equal(checkImportFile({ name: 'plan.pdf', size: 10 }), 'Choose an .xlsx or .csv file.');
  assert.equal(ROW_STATUS.duplicate.label, 'Already exists');
  assert.equal(BATCH_STATUS.staged.label, 'Waiting for confirmation');
  assert.equal(importTypeInfo('waiting_list').label, 'Waiting list');
  try {
    setActiveLanguage('so');
    assert.equal(checkImportFile({ name: 'plan.pdf', size: 10 }), 'Dooro fayl .xlsx ama .csv ah.');
    assert.equal(checkImportFile({ name: 'plan.xlsx', size: 0 }), 'Faylku waa madhan yahay.');
    assert.equal(describeImportError('PREVIEW_ONLY', 'x'), 'Kani waa horu-aragga akhris oo keliya ah. Lama xaqiijin karo.');
    assert.equal(describeImportError('SOMETHING_NEW', 'Server said this'), 'Server said this');
    assert.equal(rowCodeText('NO_MATCH'), 'Shaqaale ku habboon ma jiro');
    assert.equal(rowCodeText('IMPORTED'), 'La soo geliyey');
    assert.equal(rowCodeText('BRAND_NEW_CODE'), 'BRAND_NEW_CODE');
    assert.equal(ACTIVATION_PATH_TEXT.rehire, 'Dib u shaqaalaysiin (Aan Shaqeynayn ilaa Shaqeynaya)');
    assert.equal(ROW_STATUS.duplicate.label, 'Hore ayay u jiraan');
    assert.equal(ROW_STATUS.valid.label, 'Diyaar');
    assert.equal(BATCH_STATUS.processing.label, 'Waa la soo gelinayaa');
    assert.equal(importTypeInfo('waiting_list').label, 'Liiska sugitaanka');
    assert.deepEqual(importTypeInfo('waiting_list').required, ['full_name', 'phone (registration phone)']);
  } finally {
    setActiveLanguage('en');
  }
});

test('every row code has a Somali text and the tables keep their keys', () => {
  const codes = Object.keys(ROW_CODE_TEXT);
  assert.equal(codes.length, 39);
  try {
    setActiveLanguage('so');
    for (const code of codes) assert.notEqual(ROW_CODE_TEXT[code], '', code);
  } finally {
    setActiveLanguage('en');
  }
  assert.equal(CATALOGUE['confirm.imports.cancelTitle'].en, 'Cancel this import?');
});
