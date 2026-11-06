// Pure helpers of the Audit Log screen (no React, no `@/` imports) so Node's test runner can test them.

/**
 * Every AuditAction value the backend defines (App\Enums\AuditAction), in the enum's order. A test compares this list with the PHP enum, so an action
 * added there cannot silently be missing from the filter (as `export`, the payment-list download, once was).
 */
export const AUDIT_ACTIONS = [
  'login', 'logout', 'create', 'update', 'delete', 'approve', 'cancel', 'payment',
  'permission_update', 'role_update', 'password_change', 'password_reset',
  'payment_confirm', 'payment_reject',
  'booking_schedule', 'booking_start', 'booking_complete', 'booking_close', 'booking_cancel',
  'store_order_status_change',
  'quotation_assign', 'quotation_issue', 'quotation_revision', 'quotation_discussion_reply',
  'quotation_close_discussion', 'quotation_expire', 'quotation_cancel', 'quotation_admin_accept',
  'hero_banner_create', 'hero_banner_update', 'hero_banner_delete', 'hero_banner_publish', 'hero_banner_hide',
  'gallery_create', 'gallery_update', 'gallery_delete',
  'faq_create', 'faq_update', 'faq_delete',
  'service_feature_toggle',
  'export',
] as const;

/** The short name the table shows for a stored entity type: the part after the last backslash ("App\Models\Admin" -> "Admin"). */
export function entityTypeLabel(entityType: string): string {
  const cut = entityType.lastIndexOf(String.fromCharCode(92));

  return cut === -1 ? entityType : entityType.slice(cut + 1);
}

/**
 * The entity-type choices for the filter: the server's list (stored value + short label); and, so a shared link still works, the value in the address
 * bar when the server does not list it any more.
 */
export function entityTypeChoices(listed: readonly { value: string; label: string }[], current: string): { value: string; label: string }[] {
  const choices = listed.map((entry) => ({ value: entry.value, label: entry.label }));

  if (current !== '' && !choices.some((entry) => entry.value === current)) {
    choices.push({ value: current, label: entityTypeLabel(current) });
  }

  return choices;
}
