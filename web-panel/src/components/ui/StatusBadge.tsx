type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

const toneClasses: Record<Tone, string> = {
  success: 'bg-success-soft text-success',
  warning: 'bg-warning-soft text-warning',
  danger: 'bg-danger-soft text-danger',
  info: 'bg-secondary-soft text-secondary',
  neutral: 'bg-surface-alt text-ink-muted',
};

/** Maps a customer/booking/order status string to a semantic tone + label. Extend as new statuses appear. */
const STATUS_TONE: Record<string, Tone> = {
  ACTIVE: 'success',
  INACTIVE: 'neutral',
  BLOCKED: 'danger',
  DELETED: 'danger',
  active: 'success',
  inactive: 'neutral',
  confirmed: 'success',
  processing: 'info',
  pending: 'warning',
  cancelled: 'danger',
  paid: 'success',
  refunded: 'warning',
};

interface StatusBadgeProps {
  status: string;
  tone?: Tone;
  label?: string;
}

export function StatusBadge({ status, tone, label }: StatusBadgeProps) {
  const resolvedTone = tone ?? STATUS_TONE[status] ?? 'neutral';
  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${toneClasses[resolvedTone]}`}
    >
      <span className="h-1.5 w-1.5 rounded-full bg-current" />
      {label ?? status}
    </span>
  );
}
