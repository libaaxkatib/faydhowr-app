import type { IconName } from '@/components/ui/Icon';

export interface NavLeaf {
  label: string;
  to: string;
  icon?: IconName;
  /** When true the route isn't built yet (Marketing/HRM backends are a future phase) — renders disabled. */
  comingSoon?: boolean;
  /**
   * Exact-match only (React Router's NavLink `end`). Required whenever `to`
   * is itself a path prefix of a sibling item's `to` in the same group (e.g.
   * a group's own "Dashboard" at `/marketing` vs. `/marketing/xarun`) —
   * without it, NavLink's default prefix matching marks both active at once.
   */
  end?: boolean;
  /**
   * The exact `AdminPermission` key the backend requires for this item's own
   * page (i.e. the permission on the route its primary GET/index call hits —
   * see routes/api.php). Omit only for items with no backend permission gate
   * at all (none today; the standalone Dashboard link above NAV_GROUPS is the
   * one real "always visible" case, and it isn't part of this list).
   * `comingSoon` items are intentionally left untagged — they have no real
   * backend route yet, so they ride on their group's visibility instead (see
   * Sidebar.tsx) rather than claiming a permission that doesn't exist yet.
   */
  permission?: string;
}

export interface NavGroup {
  label: string;
  items: NavLeaf[];
  comingSoon?: boolean;
}

export const NAV_GROUPS: NavGroup[] = [
  {
    label: 'Mobile App Management',
    items: [
      { label: 'Customers', to: '/mobile-app/customers', icon: 'users', permission: 'customers.view' },
      { label: 'Services', to: '/mobile-app/services', icon: 'star', comingSoon: true },
      { label: 'Products', to: '/mobile-app/products', icon: 'box', comingSoon: true },
      { label: 'Bookings', to: '/mobile-app/bookings', icon: 'calendar', comingSoon: true },
      { label: 'Quotations', to: '/mobile-app/quotations', icon: 'file-text', comingSoon: true },
      { label: 'Orders', to: '/mobile-app/orders', icon: 'cart', comingSoon: true },
      { label: 'Payments', to: '/mobile-app/payments', icon: 'credit-card', comingSoon: true },
      { label: 'Inventory', to: '/mobile-app/inventory', icon: 'box', comingSoon: true },
      { label: 'Reviews', to: '/mobile-app/reviews', icon: 'star', comingSoon: true },
      { label: 'Notifications', to: '/mobile-app/notifications', icon: 'bell', comingSoon: true },
      { label: 'CMS', to: '/mobile-app/cms', icon: 'image', comingSoon: true },
      { label: 'Reports', to: '/mobile-app/reports', icon: 'bar-chart', comingSoon: true },
      { label: 'Accounting', to: '/mobile-app/accounting', icon: 'credit-card', comingSoon: true },
    ],
  },
  {
    label: 'Marketing',
    items: [
      { label: 'Dashboard', to: '/marketing', icon: 'megaphone', end: true, permission: 'marketing.view' },
      { label: 'XARUN', to: '/marketing/xarun', icon: 'megaphone', permission: 'marketing.view' },
      { label: 'PROJECT', to: '/marketing/project', icon: 'briefcase', permission: 'marketing.view' },
      { label: 'Follow-ups', to: '/marketing/follow-ups', icon: 'bell', permission: 'marketing.view' },
      { label: 'Teams', to: '/marketing/teams', icon: 'users', permission: 'marketing.view' },
      { label: 'Employees', to: '/marketing/employees', icon: 'list', permission: 'marketing.view' },
      { label: 'Commission', to: '/marketing/commission', icon: 'credit-card', permission: 'marketing.commission.view' },
      { label: 'Reports', to: '/marketing/reports', icon: 'bar-chart', permission: 'marketing.reports.view' },
    ],
  },
  {
    label: 'Human Resources',
    items: [
      { label: 'Dashboard', to: '/hr', icon: 'briefcase', end: true, permission: 'hr.view' },
      { label: 'Employee Registration', to: '/hr/employees', icon: 'plus', permission: 'hr.view' },
      { label: 'Employees', to: '/hr/employees', icon: 'users', permission: 'hr.view' },
      { label: 'Damiin Needed', to: '/hr/pipeline/damiin', icon: 'file-text', permission: 'hr.view' },
      { label: 'Contract Pending', to: '/hr/pipeline/contracts', icon: 'file-text', permission: 'hr.view' },
      { label: 'Uniform Pending', to: '/hr/pipeline/uniform', icon: 'box', permission: 'hr.view' },
      { label: 'Need Training', to: '/hr/pipeline/training', icon: 'calendar', permission: 'hr.view' },
      { label: 'Need Practical', to: '/hr/pipeline/practical', icon: 'eye', permission: 'hr.view' },
      { label: 'Practical Repeat', to: '/hr/pipeline/practical-repeat', icon: 'refresh', permission: 'hr.view' },
      { label: 'Rejected', to: '/hr/pipeline/rejected', icon: 'x', permission: 'hr.view' },
      { label: 'Rejected (Historical)', to: '/hr/pipeline/rejected-historical', icon: 'x', permission: 'hr.view' },
      { label: 'Waiting', to: '/hr/waiting', icon: 'calendar', permission: 'hr.view' },
      { label: 'Workforce Requests', to: '/hr/workforce-requests', icon: 'briefcase', permission: 'hr.view' },
      { label: 'Training Batches', to: '/hr/training-batches', icon: 'list', permission: 'hr.view' },
      { label: 'Practical Batches', to: '/hr/practical-batches', icon: 'list', permission: 'hr.view' },
      { label: 'Temporary Replacements', to: '/hr/temporary-replacements', icon: 'refresh', permission: 'hr.view' },
      { label: 'Former Employees', to: '/hr/former-employees', icon: 'x', permission: 'hr.view' },
      { label: 'Supervisor Pool', to: '/hr/supervisor-pool', icon: 'shield', permission: 'hr.view' },
      { label: 'Office Staff', to: '/hr/office-staff', icon: 'users', permission: 'hr.view' },
      { label: 'Attendance', to: '/hr/attendance', icon: 'calendar', permission: 'hr.view' },
      { label: 'Departments', to: '/hr/departments', icon: 'box', permission: 'hr.view' },
      { label: 'Positions', to: '/hr/positions', icon: 'shield', permission: 'hr.view' },
      { label: 'Companies & Locations', to: '/hr/companies', icon: 'briefcase', permission: 'hr.view' },
      { label: 'Reports', to: '/hr/reports', icon: 'bar-chart', end: true, permission: 'hr.reports.view' },
      { label: 'Waiting Analytics', to: '/hr/reports/waiting-analytics', icon: 'bar-chart', permission: 'hr.reports.view' },
      { label: 'Workforce Request History', to: '/hr/reports/workforce-requests', icon: 'bar-chart', permission: 'hr.reports.view' },
      { label: 'Temporary Replacement History', to: '/hr/reports/temporary-replacements', icon: 'bar-chart', permission: 'hr.reports.view' },
      { label: 'Leave Report', to: '/hr/reports/leaves', icon: 'bar-chart', permission: 'hr.reports.view' },
      { label: 'Performance Report', to: '/hr/reports/performance-reviews', icon: 'bar-chart', permission: 'hr.reports.view' },
      { label: 'Financial Ledger', to: '/hr/reports/financial-ledger', icon: 'bar-chart', permission: 'hr.reports.view' },
      { label: 'Payroll Rollup', to: '/hr/reports/payroll-rollup', icon: 'bar-chart', permission: 'hr.reports.view' },
    ],
  },
  {
    label: 'System',
    items: [
      { label: 'Admin Users', to: '/system/admins', icon: 'users', permission: 'admins.manage' },
      { label: 'Roles & Permissions', to: '/system/roles', icon: 'shield', permission: 'roles.manage' },
      { label: 'Settings', to: '/system/settings', icon: 'settings', permission: 'settings.view' },
      { label: 'Audit Log', to: '/system/audit-log', icon: 'list', permission: 'admins.manage' },
      { label: 'Data Issues & Reconciliation', to: '/system/data-issues', icon: 'alert-triangle', permission: 'reconciliation.view' },
    ],
  },
];
