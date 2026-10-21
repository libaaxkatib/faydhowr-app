import { Link } from 'react-router-dom';

import { useEffectivePermissions } from '@/hooks/usePermissions';
import { resolveBreadcrumbTarget } from '@/utils/breadcrumbTarget';

export interface BreadcrumbItem {
  label: string;
  to?: string;
}

export function Breadcrumb({ items }: { items: BreadcrumbItem[] }) {
  const { hasPermission } = useEffectivePermissions();

  return (
    <nav className="mt-1 flex items-center gap-1.5 text-xs text-ink-muted">
      {items.map((item, index) => (
        <span key={item.label} className="flex items-center gap-1.5">
          {index > 0 && <span className="text-ink-faint">/</span>}
          {item.to ? (
            <Link to={resolveBreadcrumbTarget(item.to, hasPermission)} className="hover:text-primary">
              {item.label}
            </Link>
          ) : (
            <span className="text-ink">{item.label}</span>
          )}
        </span>
      ))}
    </nav>
  );
}
