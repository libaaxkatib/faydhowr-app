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
      { label: 'Recruitment', to: '/hr/recruitment', icon: 'file-text' },
      { label: 'Practical', to: '/hr/practical', icon: 'check' },
      { label: 'Waiting', to: '/hr/waiting', icon: 'calendar' },
      { label: 'Departments', to: '/hr/departments', icon: 'box' },
      { label: 'Positions', to: '/hr/positions', icon: 'shield' },
      { label: 'Companies & Locations', to: '/hr/companies', icon: 'briefcase' },
      { label: 'Reports', to: '/hr/reports', icon: 'bar-chart' },
    ],
  },
  {
    label: 'System',
    items: [
      { label: 'Admin Users', to: '/system/admins', icon: 'users', comingSoon: true },
      { label: 'Roles & Permissions', to: '/system/roles', icon: 'shield', comingSoon: true },
      { label: 'Settings', to: '/system/settings', icon: 'settings', comingSoon: true },
      { label: 'Audit Log', to: '/system/audit-log', icon: 'list', comingSoon: true },
    ],
  },
];
