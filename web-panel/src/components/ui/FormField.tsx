import { cloneElement, isValidElement, type ReactElement, type ReactNode } from 'react';

interface FormFieldProps {
  label: string;
  htmlFor: string;
  error?: string;
  required?: boolean;
  hint?: string;
  children: ReactNode;
}

export function FormField({ label, htmlFor, error, required, hint, children }: FormFieldProps) {
  // A screen reader hears the error or hint with the field, and the field is marked invalid, when the control is the one this label points at.
  const errorId = error ? `${htmlFor}-error` : undefined;
  const hintId = hint && !error ? `${htmlFor}-hint` : undefined;
  const describedBy = errorId ?? hintId;
  const field =
    isValidElement(children) && (children.props as { id?: string }).id === htmlFor
      ? cloneElement(children as ReactElement<Record<string, unknown>>, {
          'aria-invalid': error ? true : undefined,
          'aria-describedby': describedBy ?? (children.props as Record<string, unknown>)['aria-describedby'],
        })
      : children;

  return (
    <div className="mb-4">
      <label htmlFor={htmlFor} className="mb-1.5 block text-sm font-medium text-ink">
        {label} {required && <span className="text-danger" aria-hidden="true">*</span>}
      </label>
      {field}
      {hint && !error && <p id={hintId} className="mt-1 text-xs text-ink-faint">{hint}</p>}
      {error && <p id={errorId} role="alert" className="mt-1 text-xs text-danger">{error}</p>}
    </div>
  );
}

export const inputClasses =
  'h-10 w-full rounded-sm border border-border bg-surface px-3 text-sm text-ink outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20 disabled:bg-surface-alt disabled:text-ink-faint';
