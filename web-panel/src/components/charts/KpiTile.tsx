import { Icon, type IconName } from '@/components/ui/Icon';
import { Card } from '@/components/ui/Card';

export type KpiTone = 'default' | 'success' | 'warning' | 'danger';

const TONE_CLASSES: Record<KpiTone, string> = {
  default: 'bg-primary-soft text-primary',
  success: 'bg-success-soft text-success',
  warning: 'bg-warning-soft text-warning',
  danger: 'bg-danger-soft text-danger',
};

/**
 * A headline number of a report: the figure large, what it counts above it, and an optional hint under it (a period, a share, a "needs attention"
 * note). The tone only colours the icon; the figure and its label are always text, so nothing depends on colour alone.
 */
export function KpiTile({ label, value, hint, icon, tone = 'default' }: { label: string; value: string; hint?: string; icon: IconName; tone?: KpiTone }) {
  return (
    <Card className="p-4">
      <div className="flex items-start gap-3">
        <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-md ${TONE_CLASSES[tone]}`} aria-hidden="true">
          <Icon name={icon} size={18} />
        </span>
        <div className="min-w-0">
          <p className="text-xs font-medium leading-snug text-ink-muted">{label}</p>
          <p className="font-display text-2xl font-bold leading-tight text-ink">{value}</p>
          {hint && <p className="mt-0.5 text-xs text-ink-faint">{hint}</p>}
        </div>
      </div>
    </Card>
  );
}
