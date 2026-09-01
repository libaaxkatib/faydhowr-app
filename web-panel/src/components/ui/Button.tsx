import { forwardRef, type ButtonHTMLAttributes } from 'react';
import { Icon } from '@/components/ui/Icon';

type Variant = 'primary' | 'secondary' | 'outline' | 'ghost' | 'danger';
type Size = 'sm' | 'md';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
  isLoading?: boolean;
}

const variantClasses: Record<Variant, string> = {
  primary: 'bg-primary text-white hover:bg-primary-ink disabled:bg-primary/50',
  secondary: 'bg-secondary text-white hover:brightness-95 disabled:bg-secondary/50',
  outline: 'border border-border bg-surface text-ink hover:bg-surface-alt',
  ghost: 'text-ink-muted hover:bg-surface-alt hover:text-ink',
  danger: 'bg-danger text-white hover:brightness-95 disabled:bg-danger/50',
};

const sizeClasses: Record<Size, string> = {
  sm: 'h-8 px-3 text-xs',
  md: 'h-10 px-4 text-sm',
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  ({ variant = 'primary', size = 'md', isLoading, disabled, className = '', children, ...rest }, ref) => {
    return (
      <button
        ref={ref}
        disabled={disabled || isLoading}
        className={`inline-flex items-center justify-center gap-2 rounded-sm font-semibold font-display transition disabled:cursor-not-allowed ${variantClasses[variant]} ${sizeClasses[size]} ${className}`}
        {...rest}
      >
        {isLoading && <Icon name="loader" size={14} />}
        {children}
      </button>
    );
  },
);
Button.displayName = 'Button';
