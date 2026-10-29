import type { ReactNode } from 'react';

import { Card, CardBody, CardHeader } from '@/components/ui/Card';

/** The frame of one chart: a title (and optional note) above the chart, and an empty message instead of a blank chart when there is nothing to show. */
export function ChartCard({
  title,
  note,
  empty,
  emptyText,
  className = '',
  children,
}: {
  title: string;
  note?: string;
  /** True when there is nothing to draw: the card then shows `emptyText` (never a blank or a fake chart). */
  empty?: boolean;
  emptyText?: string;
  className?: string;
  children: ReactNode;
}) {
  return (
    <Card className={className}>
      <CardHeader>
        <div className="min-w-0">
          <h3 className="font-display text-sm font-bold text-ink">{title}</h3>
          {note && <p className="mt-0.5 text-xs text-ink-muted">{note}</p>}
        </div>
      </CardHeader>
      <CardBody>{empty ? <p className="text-sm text-ink-muted">{emptyText}</p> : children}</CardBody>
    </Card>
  );
}
