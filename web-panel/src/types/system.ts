import type { Admin, AdminRole, AdminStatus } from '@/types/admin';

/**
 * Roles offered when assigning/creating an admin from the Fayadhowr Web Panel
 * (HR & Marketing), per the confirmed final RBAC decision: only these three
 * are delegated top-level Web Panel roles (super_admin is never assignable
 * through this UI).
 *
 * manager/sales/inventory/accountant are confirmed Mobile App roles — the
 * original Admin Panel roles per docs/02_SRS.md ("Admin Panel roles are
 * exactly these five: Super Admin, Manager, Sales, Inventory, Accountant")
 * for the Store/Booking/Payments/Accounting scope. They remain fully intact
 * in the database and backend and are deliberately not offered here; this Web
 * Panel Phase 1 build does not manage Mobile App roles. hr_employee/
 * marketing_employee also remain valid database values (employees under
 * their manager, not separate top-level roles).
 */
export const ASSIGNABLE_ADMIN_ROLES: { value: AdminRole; label: string }[] = [
  { value: 'hr_manager', label: 'HR Manager' },
  { value: 'marketing_manager', label: 'Marketing Manager' },
  { value: 'mobile_app_manager', label: 'Mobile App Manager' },
];

/** Every AdminRole value the database accepts — used wherever a role needs to be *displayed*, not assigned. */
export const ADMIN_ROLE_LABELS: Record<AdminRole, string> = {
  super_admin: 'Super Admin',
  manager: 'Manager',
  sales: 'Sales',
  inventory: 'Inventory',
  accountant: 'Accountant',
  hr_manager: 'HR Manager',
  hr_employee: 'HR Employee',
  marketing_manager: 'Marketing Manager',
  marketing_employee: 'Marketing Employee',
  mobile_app_manager: 'Mobile App Manager',
};

export interface ListAdminsParams {
  role?: AdminRole;
  status?: AdminStatus;
  search?: string;
  page?: number;
  per_page?: number;
}

export interface CreateAdminPayload {
  full_name: string;
  email: string;
  phone: string;
  password: string;
  role: AdminRole;
  status: AdminStatus;
}

/** No `password` field — the backend's UpdateAdminAction does not accept one (see Phase 2). */
export interface UpdateAdminPayload {
  full_name?: string;
  email?: string;
  phone?: string;
  role?: AdminRole;
  status?: AdminStatus;
}

export interface Permission {
  key: string;
  name: string;
  group: string;
}

export interface RolePermissions {
  role: AdminRole;
  permissions: Permission[];
}

export interface AdminPermissionsDetail {
  role: AdminRole;
  role_permissions: Permission[];
  direct_permissions: Permission[];
  effective_permissions: Permission[];
}

export type { Admin };
