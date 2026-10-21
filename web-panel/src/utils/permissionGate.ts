// Pure rule behind <PermissionGate> (no React, no `@/` imports) so Node's test runner can import it.
//
// <PermissionGate module="hr"> / <PermissionGate module="marketing"> wrap ACTION buttons (add, edit, delete, record ...). They used to ask
// "is the module visible on the dashboard?", which depends on a different permission (`dashboard.view`): a manager without it lost every
// Add/Edit button although the server would have accepted the change, and a viewer WITH it was offered buttons the server refuses. For these
// two modules the gate now asks for the permission the server really requires for those actions (hr.manage / marketing.manage; a control
// that needs another key, such as marketing.assign, names it with the `permission` prop). Other modules keep the module-visibility rule.
// The server still enforces every action; this only decides what is shown.

export const MODULE_ACTION_PERMISSION: Readonly<Record<string, string>> = { hr: 'hr.manage', marketing: 'marketing.manage' };

export function gateAllows(
  module: string,
  hasPermission: (key: string) => boolean,
  canAccessModule: (module: string) => boolean,
  permission?: string,
): boolean {
  const required = permission ?? MODULE_ACTION_PERMISSION[module];

  return required !== undefined ? hasPermission(required) : canAccessModule(module);
}
