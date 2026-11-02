/**
 * Show / Hide password. A password field is hidden by default; the eye button only flips what the browser draws (`text` instead of `password`). The value
 * itself, the form and the submission are never touched by it.
 */
export type PasswordInputType = 'password' | 'text';

export function passwordInputType(visible: boolean): PasswordInputType {
  return visible ? 'text' : 'password';
}

export function toggledVisibility(visible: boolean): boolean {
  return !visible;
}
