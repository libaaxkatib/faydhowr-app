import type { HTMLAttributes, ReactNode } from 'react';

interface CardProps extends HTMLAttributes<HTMLDivElement> {
  children: ReactNode;
}

export function Card({ children, className = '', ...rest }: CardProps) {
  return (
    <div
      className={`rounded-lg border border-border bg-surface shadow-card ${className}`}
      {...rest}
    >
      {children}
    </div>
  );
}

export function CardHeader({ children, className = '', ...rest }: CardProps) {
  return (
    <div
      className={`flex items-center justify-between gap-3 border-b border-border px-5 py-4 ${className}`}
      {...rest}
    >
      {children}
    </div>
  );
}

export function CardBody({ children, className = '', ...rest }: CardProps) {
  return (
    <div className={`p-5 ${className}`} {...rest}>
      {children}
    </div>
  );
}
