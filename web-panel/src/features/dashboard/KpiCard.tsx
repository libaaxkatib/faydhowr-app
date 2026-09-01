import { Card } from '@/components/ui/Card';
import { Icon, type IconName } from '@/components/ui/Icon';
import { formatCurrency, formatNumber } from '@/utils/formatters';
import type { DashboardWidget } from '@/types/dashboard';

interface KpiCardProps {
  widget: DashboardWidget;
  icon: IconName;
  iconClassName: string;
}

export function KpiCard({ widget, icon, iconClassName }: KpiCardProps) {
  const value = widget.unit === 'currency' ? formatCurrency(widget.total) : formatNumber(widget.total);

  return (
    <Card className="p-5">
      <div className="flex items-center gap-3">
        <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-white ${iconClassName}`}>
          <Icon name={icon} size={19} />
        </div>
        <div className="min-w-0">
          <p className="truncate text-xs font-medium text-ink-muted">{widget.label}</p>
          <p className="font-display text-xl font-bold text-ink">{value}</p>
        </div>
      </div>
    </Card>
  );
}
