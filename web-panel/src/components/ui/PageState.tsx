import { Link } from 'react-router-dom';
import { Icon, type IconName } from '@/components/ui/Icon';

/** Full-page variant of EmptyState, used for route-level 403/404s rather than an in-card empty state. */
export function PageState({
  icon,
  title,
  description,
  backTo = '/dashboard',
}: {
  icon: IconName;
  title: string;
  description: string;
  backTo?: string;
}) {
  return (
    <div className="flex min-h-[70vh] flex-col items-center justify-center gap-3 text-center">
      <div className="flex h-14 w-14 items-center justify-center rounded-full bg-surface-alt text-ink-faint">
        <Icon name={icon} size={24} />
      </div>
      <h1 className="font-display text-xl font-bold text-ink">{title}</h1>
      <p className="max-w-sm text-sm text-ink-muted">{description}</p>
      <Link to={backTo} className="mt-2 text-sm font-semibold text-primary hover:underline">
        Back to Dashboard
      </Link>
    </div>
  );
}
