import { useQuery } from '@tanstack/react-query';

import { recoveryApi } from '@/api/recovery';
import { Card } from '@/components/ui/Card';
import { TableScroll } from '@/components/ui/TableScroll';
import { useI18n } from '@/i18n/useI18n';
import type { MessageKey } from '@/i18n/messages';
import type { MutatingWorkflowId } from '@/types/recovery';

const WORKFLOW_TITLE: Record<MutatingWorkflowId, MessageKey> = {
  reopen_assignment: 'option.recovery.wf.reopen_assignment',
  restore_cancelled_assignment: 'option.recovery.wf.restore_cancelled_assignment',
  correct_assignment_dates: 'option.recovery.wf.correct_assignment_dates',
  correct_absence: 'option.recovery.wf.correct_absence',
};

/** Every applied correction: who, when, why, and what changed. The audit log holds the same record. */
export function CorrectionHistory() {
  const { t } = useI18n();
  const history = useQuery({ queryKey: ['recovery', 'corrections'], queryFn: () => recoveryApi.corrections() });

  return (
    <Card className="p-4">
      {history.data && history.data.length === 0 && <p className="text-sm text-ink-muted">{t('warning.recovery.noHistory')}</p>}
      {history.data && history.data.length > 0 && (
        <TableScroll>
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="text-xs uppercase text-ink-muted">
                <th className="px-3 py-2">{t('option.recovery.appliedAt')}</th>
                <th className="px-3 py-2">{t('option.recovery.workflow')}</th>
                <th className="px-3 py-2">{t('option.recovery.assignment')}</th>
                <th className="px-3 py-2">{t('option.recovery.reasonLabel')}</th>
                <th className="px-3 py-2">{t('option.recovery.appliedBy')}</th>
              </tr>
            </thead>
            <tbody>
              {history.data.map((row) => (
                <tr key={row.id} className="border-t border-border align-top">
                  <td className="px-3 py-2 whitespace-nowrap">{row.applied_at?.slice(0, 16).replace('T', ' ')}</td>
                  <td className="px-3 py-2">{t(WORKFLOW_TITLE[row.workflow])}{row.month ? ` (${row.month})` : ''}</td>
                  <td className="px-3 py-2">#{row.assignment_id}</td>
                  <td className="px-3 py-2">{row.reason}</td>
                  <td className="px-3 py-2">{row.applied_by}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </TableScroll>
      )}
    </Card>
  );
}
