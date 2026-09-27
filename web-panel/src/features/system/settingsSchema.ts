import type { SettingCategory, SettingFieldSchema } from '@/types/settings';

/**
 * Mirrors backend/app/Support/Settings/SettingsRegistry.php's `editable: true`
 * keys and backend/app/Http/Requests/.../UpdateSettingsRequest.php's rulesFor()
 * exactly — field list, type, and options come from there, not invented here.
 * `branch` has no form (it's a read-only pointer to the dedicated Branches
 * resource, rendered separately in SettingsPage).
 */
export const SETTINGS_FIELD_SCHEMA: Partial<Record<SettingCategory, SettingFieldSchema[]>> = {
  company: [
    { key: 'name', label: 'Company name', type: 'text' },
    { key: 'logo', label: 'Logo URL', type: 'url', hint: 'Paste a direct link to the logo image.' },
    { key: 'email', label: 'Email', type: 'email' },
    { key: 'phone', label: 'Phone', type: 'text' },
    { key: 'website', label: 'Website', type: 'url' },
    { key: 'address', label: 'Address', type: 'text' },
    { key: 'tax_id', label: 'Tax ID', type: 'text' },
    { key: 'business_hours_open', label: 'Business hours open', type: 'time' },
    { key: 'business_hours_close', label: 'Business hours close', type: 'time' },
    { key: 'facebook', label: 'Facebook', type: 'text' },
    { key: 'instagram', label: 'Instagram', type: 'text' },
    { key: 'whatsapp', label: 'WhatsApp', type: 'text' },
  ],
  currency: [
    { key: 'default', label: 'Default currency code', type: 'text', hint: 'e.g. USD' },
    { key: 'symbol', label: 'Symbol', type: 'text', hint: 'e.g. $' },
    {
      key: 'decimal_places',
      label: 'Decimal places',
      type: 'select',
      options: [
        { value: '0', label: '0' },
        { value: '2', label: '2' },
      ],
    },
    { key: 'thousand_separator', label: 'Thousand separator', type: 'text', hint: 'Single character, e.g. ,' },
  ],
  tax: [
    { key: 'default', label: 'Tax applied by default', type: 'boolean' },
    { key: 'rate', label: 'Rate (%)', type: 'number' },
    {
      key: 'mode',
      label: 'Mode',
      type: 'select',
      options: [
        { value: 'inclusive', label: 'Inclusive' },
        { value: 'exclusive', label: 'Exclusive' },
      ],
    },
  ],
  numbering: [
    { key: 'customer_prefix', label: 'Customer prefix', type: 'text', hint: 'Uppercase letters, digits, dashes only.' },
    { key: 'booking_prefix', label: 'Booking prefix', type: 'text' },
    { key: 'quotation_prefix', label: 'Quotation prefix', type: 'text' },
    { key: 'invoice_prefix', label: 'Invoice prefix', type: 'text' },
    { key: 'receipt_prefix', label: 'Receipt prefix', type: 'text' },
    { key: 'order_prefix', label: 'Order prefix', type: 'text' },
    { key: 'payment_prefix', label: 'Payment prefix', type: 'text' },
    { key: 'auto_numbering', label: 'Auto-numbering enabled', type: 'boolean' },
  ],
  smtp: [
    { key: 'host', label: 'Host', type: 'text' },
    { key: 'port', label: 'Port', type: 'number' },
    {
      key: 'encryption',
      label: 'Encryption',
      type: 'select',
      options: [
        { value: 'none', label: 'None' },
        { value: 'ssl', label: 'SSL' },
        { value: 'tls', label: 'TLS' },
      ],
    },
    { key: 'username', label: 'Username', type: 'text' },
    { key: 'password', label: 'Password', type: 'password', hint: 'Leave blank to keep the current password.' },
  ],
  notifications: [
    { key: 'email', label: 'Email notifications', type: 'boolean' },
    { key: 'browser', label: 'Browser notifications', type: 'boolean' },
    { key: 'booking_alerts', label: 'Booking alerts', type: 'boolean' },
    { key: 'quotation_alerts', label: 'Quotation alerts', type: 'boolean' },
    { key: 'payment_alerts', label: 'Payment alerts', type: 'boolean' },
  ],
  storage: [
    {
      key: 'driver',
      label: 'Driver',
      type: 'select',
      options: [
        { value: 'local', label: 'Local' },
        { value: 's3', label: 'S3' },
      ],
    },
    { key: 'max_upload_size', label: 'Max upload size (KB)', type: 'number' },
    {
      key: 'allowed_file_types',
      label: 'Allowed file types',
      type: 'multiselect',
      options: ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip'].map((v) => ({
        value: v,
        label: v,
      })),
    },
  ],
  localization: [
    { key: 'language', label: 'Language', type: 'text', hint: 'e.g. en' },
    { key: 'timezone', label: 'Timezone', type: 'timezone' },
    { key: 'date_format', label: 'Date format', type: 'text', hint: 'e.g. DD/MM/YYYY' },
    { key: 'time_format', label: 'Time format', type: 'text', hint: 'e.g. hh:mm A' },
  ],
  backup: [
    { key: 'enabled', label: 'Scheduled backups enabled', type: 'boolean' },
    { key: 'retention_days', label: 'Retention (days)', type: 'number' },
  ],
};

export const SETTINGS_CATEGORY_LABELS: Record<SettingCategory, string> = {
  company: 'Company',
  branch: 'Branches',
  currency: 'Currency',
  tax: 'Tax',
  numbering: 'Numbering',
  smtp: 'SMTP',
  notifications: 'Notifications',
  storage: 'Storage',
  localization: 'Localization',
  backup: 'Backup',
};

export const SETTINGS_CATEGORY_ORDER: SettingCategory[] = [
  'company',
  'branch',
  'currency',
  'tax',
  'numbering',
  'smtp',
  'notifications',
  'storage',
  'localization',
  'backup',
];
