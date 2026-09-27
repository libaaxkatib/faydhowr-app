/** The 10 settings categories the backend currently supports (of 16 in the full SRS spec — see report). */
export type SettingCategory =
  | 'company'
  | 'branch'
  | 'currency'
  | 'tax'
  | 'numbering'
  | 'smtp'
  | 'notifications'
  | 'storage'
  | 'localization'
  | 'backup';

export interface SettingsCategoryData {
  category: SettingCategory;
  /** Fully-qualified dotted keys, e.g. "company.name" — the exact shape the backend returns and expects back. */
  settings: Record<string, unknown>;
  last_updated_by: { name: string; role: string } | null;
  updated_at: string | null;
}

export interface Backup {
  id: string;
  size_bytes: number;
  created_by: string | null;
  created_at: string;
}

export interface Branch {
  id: number;
  code: string;
  name: string;
  city: string | null;
  status: string;
  is_default: boolean;
  activated_at: string | null;
  created_at: string | null;
}

export interface SettingsAuditLogEntry {
  id: number;
  category: string;
  /** Already the fully-qualified "category.key" string. */
  key: string;
  old_value: unknown;
  new_value: unknown;
  changed_by: { name: string; role: string } | null;
  changed_at: string | null;
  ip_address: string | null;
}

/** Field schema per category, driving the generic settings form — mirrors backend/app/Support/Settings/SettingsRegistry.php and UpdateSettingsRequest exactly. */
export type SettingFieldType = 'text' | 'email' | 'url' | 'number' | 'boolean' | 'select' | 'password' | 'time' | 'timezone' | 'multiselect';

export interface SettingFieldSchema {
  key: string;
  label: string;
  type: SettingFieldType;
  hint?: string;
  options?: { value: string; label: string }[];
}
