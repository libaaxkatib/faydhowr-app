import type { SelectHTMLAttributes } from 'react';
import { inputClasses } from '@/components/ui/FormField';
import { withCallerWidth } from '@/components/ui/selectClasses';

interface SelectOption {
  value: string;
  label: string;
}

interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  options: SelectOption[];
  placeholder?: string;
}

export function Select({ options, placeholder, className = '', ...rest }: SelectProps) {
  const base = withCallerWidth(inputClasses, className);
  // A filter has no visible label of its own: its first option ("All statuses") is what it is called, unless a label already points at it.
  const name = rest['aria-label'] === undefined && rest['aria-labelledby'] === undefined && rest.id === undefined ? placeholder : undefined;

  return (
    <select className={`${base} ${className}`} aria-label={name} {...rest}>
      {placeholder && <option value="">{placeholder}</option>}
      {options.map((option) => (
        <option key={option.value} value={option.value}>
          {option.label}
        </option>
      ))}
    </select>
  );
}
