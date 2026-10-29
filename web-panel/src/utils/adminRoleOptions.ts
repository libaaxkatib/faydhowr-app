// Pure helper for the Admin form's role box (no React, no `@/` imports) so Node's test runner can import it.

/**
 * The roles to offer when editing an account: the assignable ones, with the account's own role first when it is not one of them. A Super Admin (or a role
 * from before the Web Panel) would otherwise open with an empty role box. The role is offered only for that account, so Super Admin never becomes
 * something that can be picked for anybody else.
 */
export function withCurrentRole<T extends { value: string }>(assignable: readonly T[], current: string): { value: string }[] {
  return assignable.some((role) => role.value === current) ? [...assignable] : [{ value: current }, ...assignable];
}
