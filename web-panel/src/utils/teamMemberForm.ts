/**
 * Client-side checks for the Add Team Member form. They only save a round trip: the server validates everything again and is
 * the authority (unique e-mail/phone, team authority, role). The role is not part of the form at all.
 */
export interface TeamMemberForm {
  full_name: string;
  email: string;
  phone: string;
  password: string;
  team_id: number | '';
}

export const emptyTeamMemberForm: TeamMemberForm = { full_name: '', email: '', phone: '', password: '', team_id: '' };

export const MIN_PASSWORD_LENGTH = 8;

export function validateTeamMemberForm(form: TeamMemberForm): Partial<Record<keyof TeamMemberForm, string>> {
  const errors: Partial<Record<keyof TeamMemberForm, string>> = {};

  if (form.full_name.trim() === '') errors.full_name = 'Enter the full name.';
  if (form.email.trim() === '') errors.email = 'Enter an e-mail address.';
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())) errors.email = 'Enter a valid e-mail address.';
  if (form.phone.trim() === '') errors.phone = 'Enter a phone number.';
  if (form.password.length < MIN_PASSWORD_LENGTH) errors.password = `Use at least ${MIN_PASSWORD_LENGTH} characters.`;
  if (form.team_id === '') errors.team_id = 'Choose a team.';

  return errors;
}

/** Teams the signed-in user may add members to, as reported by the server. */
export function manageableTeams<T extends { can_manage_members?: boolean }>(teams: T[] | undefined): T[] {
  return (teams ?? []).filter((team) => team.can_manage_members === true);
}

export function buildAddMemberPayload(form: TeamMemberForm) {
  return {
    full_name: form.full_name.trim(),
    email: form.email.trim(),
    phone: form.phone.trim(),
    password: form.password,
    team_id: Number(form.team_id),
  };
}
