// Pure rules for which Marketing controls a person is offered (no React, no `@/` imports). The server enforces every one of them; this only decides
// what is shown, so nobody is offered a button whose click always ends in a 403.

interface Who {
  isSuperAdmin: boolean;
  role: string | undefined;
  hasPermission: (key: string) => boolean;
}

/** The Super Admin and the Marketing Manager manage teams and their members (server: MarketingScope::requireUnrestricted); a team member has marketing.manage too but may not. */
export function isMarketingUnrestricted(who: Pick<Who, 'isSuperAdmin' | 'role'>): boolean {
  return who.isSuperAdmin || who.role === 'marketing_manager';
}

/** Create / delete a team: unrestricted AND the real management permission. */
export function canManageMarketingTeams(who: Who): boolean {
  return isMarketingUnrestricted(who) && who.hasPermission('marketing.manage');
}

/** Record or change a commission amount, set a rate, choose any employee: unrestricted only (server: requireUnrestricted). */
export function canRecordCommissionAmounts(who: Pick<Who, 'isSuperAdmin' | 'role'>): boolean {
  return isMarketingUnrestricted(who);
}

/** A new record's team when the person belongs to exactly one team they may use (a default they can change), otherwise nothing is chosen for them. */
export function defaultTeamId(unrestricted: boolean, teams: readonly { id: number }[] | undefined): string {
  return !unrestricted && teams?.length === 1 ? String(teams[0].id) : '';
}
