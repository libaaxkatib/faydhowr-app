// Mirrors the backend's App\Enums\AdminRole exactly (10 cases — every value the
// database will actually accept). Which of these are *offered* for new admin
// assignment in the Web Panel is a separate, narrower list — see
// ASSIGNABLE_ADMIN_ROLES in '@/types/system'.
export type AdminRole =
  | 'super_admin'
  | 'manager'
  | 'sales'
  | 'inventory'
  | 'accountant'
  | 'hr_manager'
  | 'hr_employee'
  | 'marketing_manager'
  | 'marketing_employee'
  | 'mobile_app_manager';
export type AdminStatus = 'active' | 'inactive';

export interface Admin {
  id: number;
  full_name: string;
  email: string;
  phone: string | null;
  role: AdminRole;
  status: AdminStatus;
  last_login_at: string | null;
}

export interface LoginPayload {
  email: string;
  password: string;
}

export interface LoginResponseData {
  admin: Admin;
  access_token: string;
  token_type: 'Bearer';
}
