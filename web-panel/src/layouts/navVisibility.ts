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
): boolean {
  if (item.comingSoon) return false;
  if (item.superAdminOnly) return isSuperAdmin;
  if (isSuperAdmin) return true;
  if (!item.permission) return true;
  return hasPermission(item.permission);
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
export function isGroupVisible(group: NavGroup, hasPermission: (key: string) => boolean, isSuperAdmin: boolean): boolean {
  return isSuperAdmin || group.items.some((item) => isRealItemVisible(item, hasPermission, isSuperAdmin));
}

/**
 * Within an already-visible group, decide whether to render this particular
 * item. A comingSoon placeholder always renders once its group is visible
 * (same UX as before this fix, for anyone who does have real access to the
 * group) — only a real item needs its own permission check.
 */
export function isItemRenderable(item: NavLeaf, hasPermission: (key: string) => boolean, isSuperAdmin: boolean): boolean {
  return item.comingSoon === true || isRealItemVisible(item, hasPermission, isSuperAdmin);
}
