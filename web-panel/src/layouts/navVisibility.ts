// Relative, not the `@/` alias used elsewhere: this file is also imported
// directly by Node's native test runner (navVisibility.test.ts), which has
// no bundler to resolve path aliases.
import type { NavGroup, NavLeaf } from './nav.config.ts';

/**
 * Pure permission-visibility rules for the sidebar, split out of Sidebar.tsx
 * so they're testable without rendering React/DOM (see navVisibility.test.ts).
 * No React, no hooks, no network — just NAV_GROUPS data plus the caller's
 * already-resolved `hasPermission`/`isSuperAdmin` from useEffectivePermissions.
 */

/**
 * Permission gate for a *real* nav item (one with an actual backend route).
 * `comingSoon` items have no route yet, so they're never "real" by this
 * check — they only ever render once their group is already visible for
 * some other, real reason. See `isGroupVisible` / `isItemRenderable` below.
 */
export function isRealItemVisible(
  item: NavLeaf,
  hasPermission: (key: string) => boolean,
  isSuperAdmin: boolean,
  role?: string,
): boolean {
  if (item.comingSoon) return false;
  if (item.superAdminOnly) return isSuperAdmin;
  if (isSuperAdmin) return true;
  // A page the backend opens by ROLE (audit log, compliance report): the role must be one of the listed ones, on top of any permission it also needs.
  if (item.roles && (role === undefined || !item.roles.includes(role))) return false;
  if (!item.permission) return true;
  if (item.alsoPermission && !hasPermission(item.alsoPermission)) return false;
  return hasPermission(item.permission);
}

/**
 * Where an admin who may not open the Dashboard (the backend asks for dashboard.view, which only some roles hold) should land instead: the first page
 * in the sidebar they can really use, or null when there is none.
 */
export function firstAllowedPath(groups: NavGroup[], hasPermission: (key: string) => boolean, isSuperAdmin: boolean, role?: string): string | null {
  for (const group of groups) {
    for (const item of group.items) {
      if (isRealItemVisible(item, hasPermission, isSuperAdmin, role)) return item.to;
    }
  }

  return null;
}

/**
 * A group is visible only if the admin has real (non-comingSoon) access to
 * at least one item in it — never merely because the group has a title or
 * an expandable submenu. This is what makes "Mobile App Management" and
 * "Marketing" collapse entirely for an HR Manager: none of their real items'
 * required permissions (customers.view, marketing.*) are held, so the whole
 * group — including any comingSoon placeholders — disappears rather than
 * showing a section with nothing real in it.
 */
export function isGroupVisible(group: NavGroup, hasPermission: (key: string) => boolean, isSuperAdmin: boolean, role?: string): boolean {
  return isSuperAdmin || group.items.some((item) => isRealItemVisible(item, hasPermission, isSuperAdmin, role));
}

/**
 * Within an already-visible group, decide whether to render this particular
 * item. A comingSoon placeholder always renders once its group is visible
 * (same UX as before this fix, for anyone who does have real access to the
 * group) — only a real item needs its own permission check.
 */
export function isItemRenderable(item: NavLeaf, hasPermission: (key: string) => boolean, isSuperAdmin: boolean, role?: string): boolean {
  return item.comingSoon === true || isRealItemVisible(item, hasPermission, isSuperAdmin, role);
}
