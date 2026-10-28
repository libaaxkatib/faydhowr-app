import type { IconName } from '@/components/ui/Icon';
import type { MessageKey } from '@/i18n/messages';

export interface NavLeaf {
  label: string;
  /** The Somali-capable wording of the label. Dashboard, XARUN, PROJECT and CMS have none on purpose: they stay as written. */
  labelKey?: MessageKey;
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
  /** Shown to Super Admin only (the backend checks the role itself; this only hides the link). */
  superAdminOnly?: boolean;
}

export interface NavGroup {
  label: string;
  labelKey?: MessageKey;
  items: NavLeaf[];
  comingSoon?: boolean;
}

export const NAV_GROUPS: NavGroup[] = [
  // Sidebar order (owner decision): Human Resources, Marketing, Mobile App Management, System.
  {
    label: 'Human Resources',
    labelKey: 'nav.hrGroup',
    items: [
      { label: 'Dashboard', to: '/hr', icon: 'briefcase', end: true, permission: 'hr.dashboard.view' },
      { label: 'Employee Registration', labelKey: 'nav.registration', to: '/hr/employees', icon: 'plus', permission: 'hr.view' },
      { label: 'Employees', labelKey: 'nav.employees', to: '/hr/employees', icon: 'users', permission: 'hr.view' },
      { label: 'Damiin Needed', labelKey: 'nav.damiinNeeded', to: '/hr/pipeline/damiin', icon: 'file-text', permission: 'hr.view' },
      { label: 'Contract Pending', labelKey: 'nav.contractPending', to: '/hr/pipeline/contracts', icon: 'file-text', permission: 'hr.view' },
      { label: 'Uniform Pending', labelKey: 'nav.uniformPending', to: '/hr/pipeline/uniform', icon: 'box', permission: 'hr.view' },
      { label: 'Need Training', labelKey: 'nav.needTraining', to: '/hr/pipeline/training', icon: 'calendar', permission: 'hr.view' },
      { label: 'Need Practical', labelKey: 'nav.needPractical', to: '/hr/pipeline/practical', icon: 'eye', permission: 'hr.view' },
      { label: 'Practical Repeat', labelKey: 'nav.practicalRepeat', to: '/hr/pipeline/practical-repeat', icon: 'refresh', permission: 'hr.view' },
      { label: 'Rejected', labelKey: 'nav.rejected', to: '/hr/pipeline/rejected', icon: 'x', permission: 'hr.view' },
      { label: 'Rejected (Historical)', labelKey: 'nav.rejectedHistorical', to: '/hr/pipeline/rejected-historical', icon: 'x', permission: 'hr.view' },
      { label: 'Waiting', labelKey: 'nav.waiting', to: '/hr/waiting', icon: 'calendar', permission: 'hr.view' },
      { label: 'Workforce Requests', labelKey: 'nav.workforceRequests', to: '/hr/workforce-requests', icon: 'briefcase', permission: 'hr.view' },
      { label: 'Training Batches', labelKey: 'nav.trainingBatches', to: '/hr/training-batches', icon: 'list', permission: 'hr.view' },
      { label: 'Practical Batches', labelKey: 'nav.practicalBatches', to: '/hr/practical-batches', icon: 'list', permission: 'hr.view' },
      { label: 'Temporary Replacements', labelKey: 'nav.temporaryReplacements', to: '/hr/temporary-replacements', icon: 'refresh', permission: 'hr.view' },
      { label: 'Former Employees', labelKey: 'nav.formerEmployees', to: '/hr/former-employees', icon: 'x', permission: 'hr.view' },
      { label: 'Supervisor Pool', labelKey: 'nav.supervisorPool', to: '/hr/supervisor-pool', icon: 'shield', permission: 'hr.view' },
      { label: 'Office Staff', labelKey: 'nav.officeStaff', to: '/hr/office-staff', icon: 'users', permission: 'hr.view' },
      { label: 'Attendance', labelKey: 'nav.attendance', to: '/hr/attendance', icon: 'calendar', permission: 'hr.view' },
      { label: 'Departments', labelKey: 'nav.departments', to: '/hr/departments', icon: 'box', permission: 'hr.view' },
      { label: 'Positions', labelKey: 'nav.positions', to: '/hr/positions', icon: 'shield', permission: 'hr.view' },
      { label: 'Categories', labelKey: 'nav.categories', to: '/hr/categories', icon: 'list', permission: 'hr.view' },
      { label: 'Companies & Workplaces', labelKey: 'nav.companiesWorkplaces', to: '/hr/companies', icon: 'briefcase', permission: 'hr.view' },
      { label: 'Facility Inspections', labelKey: 'nav.facilityInspections', to: '/hr/facility-inspections', icon: 'eye', permission: 'hr.inspections.view' },
      { label: 'Complaints', labelKey: 'nav.complaints', to: '/hr/facility-complaints', icon: 'alert-triangle', permission: 'hr.inspections.view' },
      { label: 'Payroll', labelKey: 'nav.payroll', to: '/hr/payroll', icon: 'credit-card', permission: 'hr.payroll.view' },
      { label: 'Excel Imports', labelKey: 'nav.excelImports', to: '/hr/imports', icon: 'file-text', permission: 'hr.import' },
      { label: 'Reports', labelKey: 'nav.reports', to: '/hr/reports', icon: 'bar-chart', end: true, permission: 'hr.reports.view' },
      { label: 'Waiting Analytics', labelKey: 'nav.waitingAnalytics', to: '/hr/reports/waiting-analytics', icon: 'bar-chart', permission: 'hr.reports.view' },
      { label: 'Workforce Request History', labelKey: 'nav.workforceRequestHistory', to: '/hr/reports/workforce-requests', icon: 'bar-chart', permission: 'hr.reports.view' },
      { label: 'Temporary Replacement History', labelKey: 'nav.temporaryReplacementHistory', to: '/hr/reports/temporary-replacements', icon: 'bar-chart', permission: 'hr.reports.view' },
      { label: 'Leave Report', labelKey: 'nav.leaveReport', to: '/hr/reports/leaves', icon: 'bar-chart', permission: 'hr.reports.view' },
      { label: 'Performance Report', labelKey: 'nav.performanceReport', to: '/hr/reports/performance-reviews', icon: 'bar-chart', permission: 'hr.reports.view' },
      { label: 'Financial Ledger', labelKey: 'nav.financialLedger', to: '/hr/reports/financial-ledger', icon: 'bar-chart', permission: 'hr.reports.view' },
      { label: 'Payroll Rollup', labelKey: 'nav.payrollRollup', to: '/hr/reports/payroll-rollup', icon: 'bar-chart', permission: 'hr.reports.view' },
      { label: 'Facility Report', labelKey: 'nav.facilityReport', to: '/hr/reports/facility', icon: 'bar-chart', permission: 'hr.reports.view' },
    ],
  },
  {
    label: 'Marketing',
    labelKey: 'nav.shell.marketing',
    items: [
      { label: 'Dashboard', to: '/marketing', icon: 'megaphone', end: true, permission: 'marketing.view' },
      { label: 'XARUN', to: '/marketing/xarun', icon: 'megaphone', permission: 'marketing.view' },
      { label: 'PROJECT', to: '/marketing/project', icon: 'briefcase', permission: 'marketing.view' },
      { label: 'Follow-ups', labelKey: 'nav.shell.followUps', to: '/marketing/follow-ups', icon: 'bell', permission: 'marketing.view' },
      { label: 'Teams', labelKey: 'nav.shell.teams', to: '/marketing/teams', icon: 'users', permission: 'marketing.view' },
      { label: 'Employees', labelKey: 'nav.employees', to: '/marketing/employees', icon: 'list', permission: 'marketing.view' },
      { label: 'Commission', labelKey: 'nav.shell.commission', to: '/marketing/commission', icon: 'credit-card', permission: 'marketing.commission.view' },
      { label: 'Reports', labelKey: 'nav.reports', to: '/marketing/reports', icon: 'bar-chart', permission: 'marketing.reports.view' },
    ],
  },
  {
    label: 'Mobile App Management',
    labelKey: 'nav.shell.mobileApp',
    items: [
      { label: 'Customers', labelKey: 'nav.customers.customers', to: '/mobile-app/customers', icon: 'users', permission: 'customers.view' },
      { label: 'Services', labelKey: 'nav.shell.services', to: '/mobile-app/services', icon: 'star', comingSoon: true },
      { label: 'Products', labelKey: 'nav.shell.products', to: '/mobile-app/products', icon: 'box', comingSoon: true },
      { label: 'Bookings', labelKey: 'nav.shell.bookings', to: '/mobile-app/bookings', icon: 'calendar', comingSoon: true },
      { label: 'Quotations', labelKey: 'nav.shell.quotations', to: '/mobile-app/quotations', icon: 'file-text', comingSoon: true },
      { label: 'Orders', labelKey: 'nav.shell.orders', to: '/mobile-app/orders', icon: 'cart', comingSoon: true },
      { label: 'Payments', labelKey: 'nav.shell.payments', to: '/mobile-app/payments', icon: 'credit-card', comingSoon: true },
      { label: 'Inventory', labelKey: 'nav.shell.inventory', to: '/mobile-app/inventory', icon: 'box', comingSoon: true },
      { label: 'Reviews', labelKey: 'nav.shell.reviews', to: '/mobile-app/reviews', icon: 'star', comingSoon: true },
      { label: 'Notifications', labelKey: 'nav.shell.notifications', to: '/mobile-app/notifications', icon: 'bell', comingSoon: true },
      { label: 'CMS', to: '/mobile-app/cms', icon: 'image', comingSoon: true },
      { label: 'Reports', labelKey: 'nav.reports', to: '/mobile-app/reports', icon: 'bar-chart', comingSoon: true },
      { label: 'Accounting', labelKey: 'nav.shell.accounting', to: '/mobile-app/accounting', icon: 'credit-card', comingSoon: true },
    ],
  },
  {
    label: 'System',
    labelKey: 'nav.shell.system',
    items: [
      { label: 'Admin Users', labelKey: 'nav.shell.adminUsers', to: '/system/admins', icon: 'users', permission: 'admins.manage' },
      { label: 'Roles & Permissions', labelKey: 'nav.shell.rolesPermissions', to: '/system/roles', icon: 'shield', permission: 'roles.manage' },
      { label: 'Settings', labelKey: 'nav.shell.settings', to: '/system/settings', icon: 'settings', permission: 'settings.view' },
      { label: 'Translations', labelKey: 'nav.shell.translations', to: '/system/translations', icon: 'message-circle', superAdminOnly: true },
      { label: 'Audit Log', labelKey: 'nav.shell.auditLog', to: '/system/audit-log', icon: 'list', permission: 'admins.manage' },
      { label: 'Data Issues & Reconciliation', labelKey: 'nav.shell.dataIssues', to: '/system/data-issues', icon: 'alert-triangle', permission: 'reconciliation.view' },
    ],
  },
];
