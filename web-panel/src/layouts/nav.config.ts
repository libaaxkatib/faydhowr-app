import type { IconName } from '@/components/ui/Icon';

export interface NavLeaf {
  label: string;
  to: string;
  icon?: IconName;
  /** When true the route isn't built yet (Marketing/HRM backends are a future phase) — renders disabled. */
  comingSoon?: boolean;
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
    comingSoon: true,
    items: [
      { label: 'Dashboard', to: '/marketing', icon: 'megaphone', comingSoon: true },
      { label: 'Xarun', to: '/marketing/xarun', icon: 'megaphone', comingSoon: true },
      { label: 'Project', to: '/marketing/project', icon: 'briefcase', comingSoon: true },
      { label: 'Follow-ups', to: '/marketing/follow-ups', icon: 'bell', comingSoon: true },
      { label: 'Teams', to: '/marketing/teams', icon: 'users', comingSoon: true },
      { label: 'Commission', to: '/marketing/commission', icon: 'credit-card', comingSoon: true },
      { label: 'Reports', to: '/marketing/reports', icon: 'bar-chart', comingSoon: true },
    ],
  },
  {
    label: 'Human Resources',
    comingSoon: true,
    items: [
      { label: 'Dashboard', to: '/hrm', icon: 'briefcase', comingSoon: true },
      { label: 'Employee Registration', to: '/hrm/registration', icon: 'plus', comingSoon: true },
      { label: 'Employees', to: '/hrm/employees', icon: 'users', comingSoon: true },
      { label: 'Recruitment', to: '/hrm/recruitment', icon: 'file-text', comingSoon: true },
      { label: 'Practical', to: '/hrm/practical', icon: 'check', comingSoon: true },
      { label: 'Waiting', to: '/hrm/waiting', icon: 'calendar', comingSoon: true },
      { label: 'Departments', to: '/hrm/departments', icon: 'box', comingSoon: true },
      { label: 'Positions', to: '/hrm/positions', icon: 'shield', comingSoon: true },
      { label: 'Reports', to: '/hrm/reports', icon: 'bar-chart', comingSoon: true },
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
