import { Icon } from '@/components/ui/Icon';

export function LoadingState({ label = 'Loading…' }: { label?: string }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-16 text-ink-muted">
      <Icon name="loader" size={22} className="text-primary" />
      <p className="text-sm">{label}</p>
    </div>
  );
}
