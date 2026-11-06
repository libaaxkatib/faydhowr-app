import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { LoadingState } from '@/components/ui/LoadingState';
import { Modal } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/useToast';
import { useI18n } from '@/i18n/useI18n';
import { formatDate } from '@/utils/formatters';
import { friendlyErrorMessage, localToday } from '@/utils/hrHelpers';
import type { ClientCompany } from '@/types/employee';

function ErrorLine({ message }: { message: string | null }) {
  return message ? <div role="alert" className="mb-4 rounded-md border border-danger/30 bg-danger-soft px-3.5 py-2.5 text-sm text-danger">{message}</div> : null;
}

/**
 * Close a company / facility that stopped operating. The last day of operation and a reason are required; every ACTIVE assignment of this company ends on that
 * day (the server does it in one transaction), so those employees stop being paid by this company afterwards. Their assignments elsewhere, their HR status,
 * payroll, payments and history are not touched, and Temporary covers are listed for HR instead of being ended.
 */
export function CloseCompanyDialog({ company, onClose }: { company: ClientCompany; onClose: () => void }) {
  const { t } = useI18n();
  const { show } = useToast();
  const queryClient = useQueryClient();
  const [date, setDate] = useState(localToday());
  const [reason, setReason] = useState('');
  const [correctionReason, setCorrectionReason] = useState('');
  const [serverError, setServerError] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: () => hrApi.clientCompanies.close(company.id, { effective_date: date, reason: reason.trim(), ...(correctionReason.trim() !== '' ? { correction_reason: correctionReason.trim() } : {}) }),
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['client-companies'] });
      void queryClient.invalidateQueries({ queryKey: ['company-employees'] });
      void queryClient.invalidateQueries({ queryKey: ['payroll-overview'] });
      void queryClient.invalidateQueries({ queryKey: ['payroll-runs'] });
      show(t('notify.correction.companyClosed', { ended: result.summary?.assignments_ended ?? 0 }));
      onClose();
    },
    onError: (err) => setServerError(friendlyErrorMessage(err, t('notify.company.somethingWentWrongNothingChanged'))),
  });

  return (
    <Modal
      isOpen
      onClose={mutation.isPending ? () => undefined : onClose}
      title={t('confirm.correction.closeTitle', { name: company.name })}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>{t('action.cancel')}</Button>
          <Button size="sm" isLoading={mutation.isPending} disabled={date === '' || reason.trim().length < 5} onClick={() => { setServerError(null); mutation.mutate(); }} data-testid="close-company-confirm">
            {t('action.correction.closeCompany')}
          </Button>
        </>
      }
    >
      <ErrorLine message={serverError} />
      <p className="mb-3 text-sm text-ink-muted">{t('confirm.correction.closeBody', { name: company.name })}</p>
      <FormField label={t('option.correction.closeDate')} htmlFor="close-company-date" required>
        <input id="close-company-date" type="date" className={inputClasses} value={date} onChange={(e) => setDate(e.target.value)} />
      </FormField>
      <FormField label={t('option.correction.closeReason')} htmlFor="close-company-reason" required hint={t('validation.correction.reasonHint5')}>
        <textarea id="close-company-reason" rows={2} maxLength={500} className={`${inputClasses} h-auto py-2`} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
      <FormField label={t('option.correction.payrollCorrectionReason')} htmlFor="close-company-correction">
        <input id="close-company-correction" maxLength={500} className={inputClasses} value={correctionReason} onChange={(e) => setCorrectionReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}

/**
 * Reopen a company that was closed by mistake. The server lists every assignment THAT closure ended or cancelled, with the reason when one cannot come back
 * (the employee is no longer active, the position was refilled, a clash with another assignment). The person ticks the ones to restore - nothing is
 * restored without that confirmation, and no other assignment is ever touched.
 */
export function ReopenCompanyDialog({ company, onClose }: { company: ClientCompany; onClose: () => void }) {
  const { t } = useI18n();
  const { show } = useToast();
  const queryClient = useQueryClient();
  const [reason, setReason] = useState('');
  const [correctionReason, setCorrectionReason] = useState('');
  const [chosen, setChosen] = useState<number[]>([]);
  const [serverError, setServerError] = useState<string | null>(null);
  const closure = useQuery({ queryKey: ['company-closure', company.id], queryFn: () => hrApi.clientCompanies.closure(company.id) });
  const rows = closure.data?.assignments ?? [];
  const covers = closure.data?.summary?.covers_to_review ?? [];

  const mutation = useMutation({
    mutationFn: () => hrApi.clientCompanies.reopen(company.id, { reason: reason.trim(), restore_assignment_ids: chosen, ...(correctionReason.trim() !== '' ? { correction_reason: correctionReason.trim() } : {}) }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['client-companies'] });
      void queryClient.invalidateQueries({ queryKey: ['company-employees'] });
      void queryClient.invalidateQueries({ queryKey: ['company-closure', company.id] });
      void queryClient.invalidateQueries({ queryKey: ['payroll-overview'] });
      void queryClient.invalidateQueries({ queryKey: ['payroll-runs'] });
      show(t('notify.correction.companyReopened'));
      onClose();
    },
    onError: (err) => setServerError(friendlyErrorMessage(err, t('notify.company.somethingWentWrongNothingChanged'))),
  });

  const toggle = (id: number) => setChosen((current) => (current.includes(id) ? current.filter((x) => x !== id) : [...current, id]));

  return (
    <Modal
      isOpen
      onClose={mutation.isPending ? () => undefined : onClose}
      title={t('confirm.correction.reopenCompanyTitle', { name: company.name })}
      size="md"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>{t('action.cancel')}</Button>
          <Button size="sm" isLoading={mutation.isPending} disabled={closure.isLoading || reason.trim().length < 5} onClick={() => { setServerError(null); mutation.mutate(); }} data-testid="reopen-company-confirm">
            {t('action.correction.reopenCompany')}
          </Button>
        </>
      }
    >
      <ErrorLine message={serverError} />
      {closure.data?.reason && closure.data.effective_date && <p className="mb-2 text-xs text-ink-muted">{t('option.correction.closedOn', { date: formatDate(closure.data.effective_date), reason: closure.data.reason })}</p>}
      <p className="mb-3 text-sm text-ink-muted">{t('confirm.correction.reopenCompanyBody')}</p>
      {closure.isLoading && <LoadingState />}
      {!closure.isLoading && rows.length === 0 && <p className="mb-3 text-sm text-ink-muted">{t('option.correction.noAssignmentsToRestore')}</p>}
      {rows.length > 0 && (
        <ul className="mb-3 divide-y divide-border rounded-sm border border-border" data-testid="closure-assignments">
          {rows.map((row) => (
            <li key={row.assignment_id} className="flex items-start gap-3 px-3 py-2 text-sm">
              <input
                type="checkbox"
                id={`restore-${row.assignment_id}`}
                className="mt-1"
                disabled={!row.restorable}
                checked={chosen.includes(row.assignment_id)}
                onChange={() => toggle(row.assignment_id)}
              />
              <label htmlFor={`restore-${row.assignment_id}`} className="min-w-0 flex-1">
                <span className="font-medium text-ink">{row.employee_name} ({row.employee_number})</span>
                <span className="block text-xs text-ink-muted">{row.position ?? '—'}{row.action === 'cancelled' ? ` · ${t('option.correction.restoreCancelled')}` : row.ended_on ? ` · ${formatDate(row.ended_on)}` : ''}</span>
                {!row.restorable && <span className="block text-xs text-danger">{t('option.correction.notRestorable', { why: row.conflict ?? '' })}</span>}
              </label>
            </li>
          ))}
        </ul>
      )}
      {covers.length > 0 && <p className="mb-3 rounded-sm border border-warning/30 bg-warning-soft px-3 py-2 text-xs text-warning" data-testid="closure-covers">{t('option.correction.coversToReview', { count: covers.length })}</p>}
      <FormField label={t('option.correction.reason')} htmlFor="reopen-company-reason" required hint={t('validation.correction.reasonHint5')}>
        <textarea id="reopen-company-reason" rows={2} maxLength={500} className={`${inputClasses} h-auto py-2`} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
      <FormField label={t('option.correction.payrollCorrectionReason')} htmlFor="reopen-company-correction">
        <input id="reopen-company-correction" maxLength={500} className={inputClasses} value={correctionReason} onChange={(e) => setCorrectionReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}
