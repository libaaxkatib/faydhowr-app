// Pure helpers for finding people on the Marketing Employees page (no React, no `@/` imports) so Node's test runner can import them. The page
// receives the whole list of marketing accounts (it is short), so it is searched and filtered here; nothing is changed.

export type MarketingStaffRoleFilter = '' | 'marketing_manager' | 'marketing_employee';
export type MarketingStaffStatusFilter = '' | 'active' | 'inactive';

/** The address-bar keys of this list's filters. */
export const MARKETING_STAFF_FILTER_KEYS = ['q', 'role', 'team', 'status'] as const;

interface StaffPerson {
  full_name: string;
  email: string;
  phone?: string | null;
  role: string;
  status?: 'active' | 'inactive';
  teams?: { id: number; name: string }[];
}

export interface MarketingStaffFilters {
  search: string;
  role: MarketingStaffRoleFilter;
  team: string;
  status: MarketingStaffStatusFilter;
}

export function parseMarketingStaffFilters(get: (key: string) => string): MarketingStaffFilters {
  const role = get('role');
  const status = get('status');
  const team = get('team');

  return {
    search: get('q'),
    role: role === 'marketing_manager' || role === 'marketing_employee' ? role : '',
    team: /^\d+$/.test(team) ? team : '',
    status: status === 'active' || status === 'inactive' ? status : '',
  };
}

const norm = (value: string) => value.replace(/\s+/g, ' ').trim().toLowerCase();

/** People matching every filter at once; every word typed must appear in the name, email or phone. A person with no status counts as active. */
export function filterMarketingStaff<T extends StaffPerson>(people: readonly T[], filters: MarketingStaffFilters): T[] {
  const words = norm(filters.search).split(' ').filter(Boolean);

  return people.filter((person) => {
    if (filters.role !== '' && person.role !== filters.role) return false;
    if (filters.status !== '' && (person.status ?? 'active') !== filters.status) return false;
    if (filters.team !== '' && !(person.teams ?? []).some((team) => String(team.id) === filters.team)) return false;
    if (words.length === 0) return true;

    const haystack = norm([person.full_name, person.email, person.phone].filter(Boolean).join(' '));

    return words.every((word) => haystack.includes(word));
  });
}

/** The teams worth offering as a filter: only those at least one listed person belongs to, once each, by name. */
export function teamsOf(people: readonly StaffPerson[]): { id: number; name: string }[] {
  const seen = new Map<number, string>();
  people.forEach((person) => (person.teams ?? []).forEach((team) => seen.set(team.id, team.name)));

  return [...seen.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
}
