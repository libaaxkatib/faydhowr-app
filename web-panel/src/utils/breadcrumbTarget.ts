/**
 * Where a breadcrumb link should really go. The HR Dashboard (/hr) now has its own permission, so a person who may use the Employee screens
 * but not the dashboard must not be sent to an "Access restricted" page by the "Human Resources" crumb: they land on the Employees list instead.
 * Pure (no React, no `@/` imports) so Node's test runner can import it.
 */
export const HR_DASHBOARD_PATH = '/hr';
export const HR_FALLBACK_PATH = '/hr/employees';

export function resolveBreadcrumbTarget(to: string, hasPermission: (key: string) => boolean): string {
  if (to === HR_DASHBOARD_PATH && !hasPermission('hr.dashboard.view')) {
    return HR_FALLBACK_PATH;
  }

  return to;
}
