export type AdminRole = 'super_admin' | 'manager' | 'sales' | 'inventory' | 'accountant';
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
