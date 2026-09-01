import type { ReactNode } from 'react';
import { Breadcrumb, type BreadcrumbItem } from '@/components/ui/Breadcrumb';

interface PageHeaderProps {
  title: string;
  breadcrumb?: BreadcrumbItem[];
  actions?: ReactNode;
}

export function PageHeader({ title, breadcrumb, actions }: PageHeaderProps) {
  return (
    <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 className="font-display text-2xl font-bold text-ink">{title}</h1>
        {breadcrumb && <Breadcrumb items={breadcrumb} />}
      </div>
      {actions && <div className="flex items-center gap-2">{actions}</div>}
    </div>
  );
}
