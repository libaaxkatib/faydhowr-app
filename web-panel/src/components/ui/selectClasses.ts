// Pure helper behind <Select> (no React, no `@/` imports) so Node's test runner can check it.

/**
 * The base field classes carry `w-full`. When the caller gives the select its own width (`w-44`), `w-full` must go: both utilities exist in the
 * stylesheet and `w-full` wins, which stretched every filter dropdown in the panel to the full row.
 */
export function withCallerWidth(base: string, className: string): string {
  return /(^|\s)w-\S/.test(className) ? base.replace(/(^|\s)w-full(?=\s|$)/, ' ') : base;
}
