import test from 'node:test';
import assert from 'node:assert/strict';

import { buildAddMemberPayload, emptyTeamMemberForm, manageableTeams, validateTeamMemberForm } from './teamMemberForm.ts';

const valid = { full_name: 'Amina Test', email: 'amina@example.test', phone: '+252611000000', password: 'long-enough', team_id: 1 as const };

test('an empty form reports every required field', () => {
  assert.deepEqual(Object.keys(validateTeamMemberForm(emptyTeamMemberForm)).sort(), ['email', 'full_name', 'password', 'phone', 'team_id']);
});

test('a complete form is accepted', () => {
  assert.deepEqual(validateTeamMemberForm(valid), {});
});

test('e-mail shape and the 8-character password minimum are checked', () => {
  assert.ok(validateTeamMemberForm({ ...valid, email: 'not-an-email' }).email);
  assert.ok(validateTeamMemberForm({ ...valid, password: '1234567' }).password);
  assert.equal(validateTeamMemberForm({ ...valid, password: '12345678' }).password, undefined);
});

test('the payload never carries a role, a status or any permission - the server fixes them', () => {
  const payload = buildAddMemberPayload({ ...valid, full_name: '  Amina Test ' });
  assert.deepEqual(Object.keys(payload).sort(), ['email', 'full_name', 'password', 'phone', 'team_id']);
  assert.equal(payload.full_name, 'Amina Test');
});

test('only teams the server marked manageable are offered', () => {
  assert.deepEqual(manageableTeams([{ id: 1, can_manage_members: true }, { id: 2, can_manage_members: false }, { id: 3 }]).map((t) => t.id), [1]);
  assert.deepEqual(manageableTeams(undefined), []);
});

test('a Marketing Manager is offered both teams when the server marks both manageable', () => {
  assert.deepEqual(manageableTeams([{ id: 1, can_manage_members: true }, { id: 2, can_manage_members: true }]).map((t) => t.id), [1, 2]);
});
