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
      { label: 'Customers', to: '/mobile-app/customers', icon: 'users' },
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
      { label: 'Dashboard', to: '/marketing', icon: 'megaphone', end: true },
      { label: 'XARUN', to: '/marketing/xarun', icon: 'megaphone' },
      { label: 'PROJECT', to: '/marketing/project', icon: 'briefcase' },
      { label: 'Follow-ups', to: '/marketing/follow-ups', icon: 'bell' },
      { label: 'Teams', to: '/marketing/teams', icon: 'users' },
      { label: 'Employees', to: '/marketing/employees', icon: 'list' },
      { label: 'Commission', to: '/marketing/commission', icon: 'credit-card' },
      { label: 'Reports', to: '/marketing/reports', icon: 'bar-chart' },
    ],
  },
  {
    label: 'Human Resources',
    items: [
      { label: 'Dashboard', to: '/hr', icon: 'briefcase', end: true },
      { label: 'Employee Registration', to: '/hr/employees', icon: 'plus' },
      { label: 'Employees', to: '/hr/employees', icon: 'users' },
      { label: 'Damiin Needed', to: '/hr/pipeline/damiin', icon: 'file-text' },
      { label: 'Contract Pending', to: '/hr/pipeline/contracts', icon: 'file-text' },
      { label: 'Uniform Pending', to: '/hr/pipeline/uniform', icon: 'box' },
      { label: 'Need Training', to: '/hr/pipeline/training', icon: 'calendar' },
      { label: 'Need Practical', to: '/hr/pipeline/practical', icon: 'eye' },
      { label: 'Practical Repeat', to: '/hr/pipeline/practical-repeat', icon: 'refresh' },
      { label: 'Rejected', to: '/hr/pipeline/rejected', icon: 'x' },
      { label: 'Waiting', to: '/hr/waiting', icon: 'calendar' },
      { label: 'Workforce Requests', to: '/hr/workforce-requests', icon: 'briefcase' },
      { label: 'Training Batches', to: '/hr/training-batches', icon: 'list' },
      { label: 'Practical Batches', to: '/hr/practical-batches', icon: 'list' },
      { label: 'Temporary Replacements', to: '/hr/temporary-replacements', icon: 'refresh' },
      { label: 'Former Employees', to: '/hr/former-employees', icon: 'x' },
      { label: 'Supervisor Pool', to: '/hr/supervisor-pool', icon: 'shield' },
      { label: 'Office Staff', to: '/hr/office-staff', icon: 'users' },
      { label: 'Attendance', to: '/hr/attendance', icon: 'calendar' },
      { label: 'Departments', to: '/hr/departments', icon: 'box' },
      { label: 'Positions', to: '/hr/positions', icon: 'shield' },
      { label: 'Companies & Locations', to: '/hr/companies', icon: 'briefcase' },
      { label: 'Reports', to: '/hr/reports', icon: 'bar-chart', end: true },
      { label: 'Waiting Analytics', to: '/hr/reports/waiting-analytics', icon: 'bar-chart' },
      { label: 'Workforce Request History', to: '/hr/reports/workforce-requests', icon: 'bar-chart' },
      { label: 'Temporary Replacement History', to: '/hr/reports/temporary-replacements', icon: 'bar-chart' },
      { label: 'Leave Report', to: '/hr/reports/leaves', icon: 'bar-chart' },
      { label: 'Performance Report', to: '/hr/reports/performance-reviews', icon: 'bar-chart' },
      { label: 'Financial Ledger', to: '/hr/reports/financial-ledger', icon: 'bar-chart' },
      { label: 'Payroll Rollup', to: '/hr/reports/payroll-rollup', icon: 'bar-chart' },
    ],
  },
  {
    label: 'System',
    items: [
      { label: 'Admin Users', to: '/system/admins', icon: 'users' },
      { label: 'Roles & Permissions', to: '/system/roles', icon: 'shield' },
      { label: 'Settings', to: '/system/settings', icon: 'settings' },
      { label: 'Audit Log', to: '/system/audit-log', icon: 'list' },
    ],
  },
];
