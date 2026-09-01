export type CustomerStatus = 'ACTIVE' | 'INACTIVE' | 'BLOCKED' | 'DELETED';
export type CustomerClassification = 'lead' | 'active_customer';
export type CustomerGender = 'male' | 'female';
export type PreferredLanguage = 'so' | 'en' | 'ar';

export interface CustomerSummary {
  bookings: number;
  quotations: number;
  orders: number;
  payments: number;
  total_spent: number;
}

export interface Customer {
  id: number;
  customer_number: string;
  full_name: string;
  phone: string | null;
  email: string | null;
  gender: CustomerGender | null;
  date_of_birth: string | null;
  avatar_url: string | null;
  preferred_language: PreferredLanguage | null;
  status: CustomerStatus;
  classification: CustomerClassification | null;
  tags: string[] | null;
  registered_at: string;
  last_login_at: string | null;
  /** Only populated by the single-customer `show` endpoint. */
  summary?: CustomerSummary;
}

export interface ListCustomersParams {
  search?: string;
  status?: Exclude<CustomerStatus, never>;
  registered_from?: string;
  registered_to?: string;
  sort?: string;
  page?: number;
  per_page?: number;
}

export interface CreateCustomerPayload {
  full_name: string;
  phone: string;
  email?: string | null;
  password: string;
  gender?: CustomerGender | null;
  date_of_birth?: string | null;
  preferred_language?: PreferredLanguage | null;
  tags?: string[] | null;
}

export type UpdateCustomerPayload = Partial<Omit<CreateCustomerPayload, 'password'>>;

/** Persisted statuses only — DELETED is reached exclusively via the destroy endpoint. */
export type UpdatableCustomerStatus = 'ACTIVE' | 'INACTIVE' | 'BLOCKED';
export type RestoreCustomerStatus = 'ACTIVE' | 'INACTIVE';
