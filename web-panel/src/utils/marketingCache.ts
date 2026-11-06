// Pure rule behind "refresh everything Marketing after a write" (no React, no `@/` imports, so Node's test runner can import it).
//
// Every Marketing screen keeps its data under a query key that starts with `marketing-` (records, one record, follow-ups, teams, employees, dashboard,
// reports, commission). A write on one screen can change what another shows - a follow-up status change changes the record, a new record changes the
// dashboard counters and the reports - so after a successful write every `marketing-` query is marked stale instead of naming a few of them.

export function isMarketingQueryKey(queryKey: readonly unknown[]): boolean {
  return typeof queryKey[0] === 'string' && queryKey[0].startsWith('marketing-');
}

interface QueryInvalidator {
  invalidateQueries: (filters: { predicate: (query: { queryKey: readonly unknown[] }) => boolean }) => unknown;
}

export function invalidateMarketing(client: QueryInvalidator): unknown {
  return client.invalidateQueries({ predicate: (query) => isMarketingQueryKey(query.queryKey) });
}
