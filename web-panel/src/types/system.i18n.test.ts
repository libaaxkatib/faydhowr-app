import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';

import { setActiveLanguage, tValueNow } from '../i18n/active.ts';
import { CATALOGUE } from '../i18n/messages/index.ts';
import { ADMIN_ROLE_LABELS, ASSIGNABLE_ADMIN_ROLES, adminRoleLabel } from './system.ts';
import {
  SETTINGS_CATEGORY_LABELS,
  SETTINGS_CATEGORY_ORDER,
  SETTINGS_FIELD_SCHEMA,
  settingCategoryLabel,
  settingFieldHint,
  settingFieldLabel,
  settingOptionLabel,
} from '../features/system/settingsSchema.ts';
import { EDITABLE_STATUS_OPTIONS, MODULE_OPTIONS, SEVERITY_OPTIONS, STATUS_OPTIONS, TYPE_OPTIONS, translateOptions } from '../features/system/dataIssueOptions.ts';

type Key = keyof typeof CATALOGUE;
const entry = (key: string) => CATALOGUE[key as Key];

function inSomali(run: () => void): void {
  try {
    setActiveLanguage('so');
    run();
  } finally {
    setActiveLanguage('en');
  }
}

test('role labels: English is exactly what the screens always showed, Somali has an entry for every role', () => {
  for (const [role, label] of Object.entries(ADMIN_ROLE_LABELS)) {
    assert.equal(adminRoleLabel(role, tValueNow), label, `${role} in English`);
    assert.equal(entry(`status.system.role.${role}`).en, label, `${role} catalogue English`);
    inSomali(() => assert.equal(adminRoleLabel(role, tValueNow), entry(`status.system.role.${role}`).so, `${role} in Somali`));
  }
  inSomali(() => assert.equal(adminRoleLabel('some_new_role', tValueNow), 'some_new_role'));
  assert.equal(adminRoleLabel('some_new_role', tValueNow), 'some_new_role');
});

test('the assignable roles are unchanged: three values, Super Admin is never offered, English labels intact', () => {
  assert.deepEqual(ASSIGNABLE_ADMIN_ROLES.map((role) => role.value), ['hr_manager', 'marketing_manager', 'mobile_app_manager']);
  assert.deepEqual(ASSIGNABLE_ADMIN_ROLES.map((role) => role.label), ['HR Manager', 'Marketing Manager', 'Mobile App Manager']);
  assert.ok(!ASSIGNABLE_ADMIN_ROLES.some((role) => (role.value as string) === 'super_admin'));
  inSomali(() => assert.deepEqual(ASSIGNABLE_ADMIN_ROLES.map((role) => role.value), ['hr_manager', 'marketing_manager', 'mobile_app_manager']));
});

test('settings: category, field, hint and option labels are English by default and Somali on request', () => {
  for (const category of SETTINGS_CATEGORY_ORDER) {
    assert.equal(settingCategoryLabel(category, tValueNow), SETTINGS_CATEGORY_LABELS[category]);
    assert.equal(entry(`option.system.settingsCategory.${category}`).en, SETTINGS_CATEGORY_LABELS[category]);
    inSomali(() => assert.equal(settingCategoryLabel(category, tValueNow), entry(`option.system.settingsCategory.${category}`).so));

    for (const field of SETTINGS_FIELD_SCHEMA[category] ?? []) {
      const id = `${category}.${field.key}`;
      assert.equal(settingFieldLabel(category, field, tValueNow), field.label, id);
      assert.equal(settingFieldHint(category, field, tValueNow), field.hint, id);
      assert.equal(entry(`option.system.settingField.${id}`).en, field.label, `${id} label in the catalogue`);
      if (field.hint !== undefined) assert.equal(entry(`warning.system.settingHint.${id}`).en, field.hint, `${id} hint in the catalogue`);
      inSomali(() => {
        assert.equal(settingFieldLabel(category, field, tValueNow), entry(`option.system.settingField.${id}`).so, id);
        if (field.hint !== undefined) assert.equal(settingFieldHint(category, field, tValueNow), entry(`warning.system.settingHint.${id}`).so, id);
      });
      for (const option of field.options ?? []) {
        assert.equal(settingOptionLabel(category, field, option, tValueNow), option.label, `${id}.${option.value}`);
        const optionKey = `option.system.settingOption.${id}.${option.value}`;
        if (CATALOGUE[optionKey as Key]) {
          assert.equal(entry(optionKey).en, option.label);
          inSomali(() => assert.equal(settingOptionLabel(category, field, option, tValueNow), entry(optionKey).so));
        }
      }
    }
  }
});

test('data issue choices keep their stored values and English labels, and have a Somali label each', () => {
  const groups = [
    ['option.system.issueModule', MODULE_OPTIONS],
    ['option.system.issueType', TYPE_OPTIONS],
    ['option.system.issueSeverity', SEVERITY_OPTIONS],
    ['option.system.issueStatus', STATUS_OPTIONS],
  ] as const;
  for (const [prefix, options] of groups) {
    assert.deepEqual(translateOptions(options, prefix, tValueNow), options.map((option) => ({ value: option.value, label: option.label })));
    for (const option of options) assert.equal(entry(`${prefix}.${option.value}`).en, option.label, `${prefix}.${option.value}`);
    inSomali(() => {
      const translated = translateOptions(options, prefix, tValueNow);
      assert.deepEqual(translated.map((option) => option.value), options.map((option) => option.value));
      translated.forEach((option, index) => assert.equal(option.label, entry(`${prefix}.${options[index].value}`).so));
    });
  }
  assert.deepEqual(EDITABLE_STATUS_OPTIONS.map((option) => option.value), ['open', 'investigating']);
});

test('every permission the backend defines has a Somali name, and every group (except Dashboard, which stays English) a Somali group name', () => {
  const file = new URL('../../../backend/app/Enums/AdminPermission.php', import.meta.url);
  if (!existsSync(file)) return;
  const php = readFileSync(file, 'utf8');
  const keys = [...php.matchAll(/case \w+ = '([a-z_.]+)';/g)].map((match) => match[1]);
  assert.ok(keys.length >= 54, `found ${keys.length} permission keys`);
  for (const key of keys) assert.ok(CATALOGUE[`option.system.permission.${key}` as Key], `${key} has no entry`);

  const slug = (name: string) => name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
  const wanted = [...new Set([...php.slice(php.indexOf('function group()')).matchAll(/=> '([^']+)',/g)].map((match) => match[1]))];
  assert.ok(wanted.length >= 17, `found ${wanted.length} groups`);
  for (const name of wanted) {
    if (name === 'Dashboard') assert.equal(CATALOGUE[`option.system.permissionGroup.${slug(name)}` as Key], undefined);
    else assert.ok(CATALOGUE[`option.system.permissionGroup.${slug(name)}` as Key], `${name} has no group entry`);
  }
});
