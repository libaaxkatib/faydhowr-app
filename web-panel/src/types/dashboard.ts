export type DashboardFilter =
  | 'today'
  | 'yesterday'
  | 'last_7_days'
  | 'last_30_days'
  | 'this_month'
  | 'last_month'
  | 'custom_date_range';

export interface DashboardQueryParams {
  filter?: DashboardFilter;
  start_date?: string;
  end_date?: string;
}

export interface DashboardWidget {
  total: number;
  label: string;
  unit: 'records' | 'currency';
  updated_at: string;
}

/** Keys are fixed by the backend's widget registration order — see AppServiceProvider. */
export interface DashboardWidgets {
  bookings: DashboardWidget;
  quotations: DashboardWidget;
  orders: DashboardWidget;
  payments: DashboardWidget;
  revenue: DashboardWidget;
  inventory: DashboardWidget;
  customers: DashboardWidget;
}

export interface NavigationItem {
  key: string;
  label: string;
}

export interface DashboardData {
  dashboard_type: 'super_admin' | 'operations';
  role: string;
  visible_modules: string[];
  visible_navigation: NavigationItem[];
  statistics: Record<string, number>;
  widgets: DashboardWidgets;
}
