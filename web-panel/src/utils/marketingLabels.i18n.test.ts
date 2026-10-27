import test from 'node:test';
import assert from 'node:assert/strict';

import { setActiveLanguage, tNow, tValueNow } from '../i18n/active.ts';
import { CATALOGUE, translateKey } from '../i18n/messages/index.ts';
import { MIN_PASSWORD_LENGTH, validateTeamMemberForm, emptyTeamMemberForm } from './teamMemberForm.ts';
import { NEED_LABEL_KEYS, needLabel } from './marketingLabels.ts';

const NEEDS = ['General Cleaning', 'Deep Cleaning', 'Pest Control', 'Tree Cutting', 'Other Services'];

test('a stored XARUN need keeps its English value; only the label follows the language', () => {
  assert.deepEqual(Object.keys(NEED_LABEL_KEYS), NEEDS);
  const english = (key: Parameters<typeof translateKey>[1]) => translateKey('en', key);
  const somali = (key: Parameters<typeof translateKey>[1]) => translateKey('so', key);
  for (const need of NEEDS) {
    assert.equal(needLabel(need, english), need, `${need} in English`);
    assert.notEqual(needLabel(need, somali), need, `${need} in Somali`);
  }
  assert.equal(needLabel('Something else', somali), 'Something else');
});

test('the Add Team Member checks are English by default and Somali on a Somali screen', () => {
  const english = validateTeamMemberForm(emptyTeamMemberForm);
  assert.deepEqual(english, {
    full_name: 'Enter the full name.',
    email: 'Enter an e-mail address.',
    phone: 'Enter a phone number.',
    password: `Use at least ${MIN_PASSWORD_LENGTH} characters.`,
    team_id: 'Choose a team.',
  });
  try {
    setActiveLanguage('so');
    const somali = validateTeamMemberForm({ ...emptyTeamMemberForm, email: 'nope' });
    assert.equal(somali.full_name, 'Geli magaca oo dhan.');
    assert.equal(somali.email, 'Geli cinwaan iimayl oo sax ah.');
    assert.equal(somali.password, `Isticmaal ugu yaraan ${MIN_PASSWORD_LENGTH} xaraf.`);
    assert.equal(somali.team_id, 'Dooro koox.');
  } finally {
    setActiveLanguage('en');
  }
});

test('stored marketing values: English shows no translation, Somali translates every known value', () => {
  const groups: Record<string, string[]> = {
    'status.marketing.record': ['pending', 'quotation', 'done', 'cancelled'],
    'status.marketing.followUp': ['scheduled', 'completed', 'rescheduled'],
    'status.marketing.quotation': ['draft', 'sent', 'accepted', 'declined'],
    'status.marketing.commission': ['pending_calculation', 'recorded', 'calculated', 'approved', 'paid'],
    'status.marketing.historyAction': ['created', 'rescheduled', 'completed', 'feedback_updated', 'status_updated'],
    'option.marketing.party': ['company', 'engineer', 'owner', 'other'],
  };
  for (const [prefix, values] of Object.entries(groups)) {
    for (const value of values) {
      assert.equal(tValueNow(prefix, value), undefined, `${prefix}.${value} in English`);
      assert.ok(`${prefix}.${value}` in CATALOGUE, `${prefix}.${value} exists`);
    }
  }
  try {
    setActiveLanguage('so');
    for (const [prefix, values] of Object.entries(groups)) {
      for (const value of values) assert.ok(tValueNow(prefix, value), `${prefix}.${value} in Somali`);
    }
    assert.equal(tValueNow('status.marketing.record', 'unknown-value'), undefined);
  } finally {
    setActiveLanguage('en');
  }
});

test('the XARUN and PROJECT terms are never translated and are filled in as parameters', () => {
  for (const type of ['XARUN', 'PROJECT']) {
    assert.equal(tNow('action.marketing.newRecord', { type }), `New ${type}`);
    assert.equal(tNow('warning.marketing.noRecordsFound', { type }), `No ${type} records found`);
    try {
      setActiveLanguage('so');
      assert.ok(tNow('action.marketing.newRecord', { type }).includes(type));
      assert.ok(tNow('notify.marketing.recordRegistered', { type }).includes(type));
    } finally {
      setActiveLanguage('en');
    }
  }
});

test('every marketing message has the same English text the screens showed before', () => {
  const expected: Record<string, string> = {
    'nav.marketing.dashboardTitle': 'Marketing Dashboard',
    'warning.marketing.commissionManualBody': 'The calculation formula is not approved, so nothing is computed or estimated here: an entry shows "Pending calculation" until the Marketing Manager types the agreed amount.',
    'warning.marketing.accountCreatedNote': "The account is created as a Marketing Employee and sees only its own team's work.",
    'option.marketing.effectiveFromDate': 'Effective from {date}',
  };
  for (const [key, text] of Object.entries(expected)) assert.equal(CATALOGUE[key as keyof typeof CATALOGUE].en, text, key);
  assert.equal(tNow('warning.marketing.passwordHint', { min: 8 }), 'At least 8 characters. Give it to the member privately; they can change it after signing in.');
});
