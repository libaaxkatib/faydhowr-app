import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/useToast';
import { GenerationWarningsPanel } from '@/features/hrm/GenerationWarningsPanel';
import { useI18n } from '@/i18n/useI18n';
import { draftFailureMessage, errorCodeOf } from '@/utils/hrHelpers';
import { expectedStatusOf, generateResetsReview } from '@/utils/payrollOverview';
import type { PayrollOverviewCompany } from '@/types/payroll';

/**
 * Generates (or refreshes) the payroll of ONE company for a month - the only way to generate; there is no month-wide Generate. The dialog says that only this
 * company is generated and nothing is paid, and, for a Reviewed company, that generating resets its review to Draft. Any Temporary Replacement warnings are
 * listed, but they never block: the confirm button is always available. The section status the screen showed goes with the request, so a change made by
 * someone else in the meantime is refused (the person is told to refresh).
 */
export function GenerateCompanyDialog({ period, monthLabel, name, row, onClose }: { period: string; monthLabel: string; name: string; row: PayrollOverviewCompany; onClose: () => void }) {
  const { t } = useI18n();
  const { show } = useToast();
  const queryClient = useQueryClient();
  const [serverError, setServerError] = useState<string | null>(null);

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['payroll-overview'] });
    void queryClient.invalidateQueries({ queryKey: ['payroll-runs'] });
    void queryClient.invalidateQueries({ queryKey: ['payroll-run'] });
    void queryClient.invalidateQueries({ queryKey: ['payroll-warnings'] });
    void queryClient.invalidateQueries({ queryKey: ['workplace-payments'] });
  };

  const mutation = useMutation({
    mutationFn: () => hrApi.payrollRuns.generateCompany(period, row.client_company_id, expectedStatusOf(row)),
    onSuccess: () => {
      refresh();
      show(t('notify.payroll.companyGenerated', { name, month: monthLabel }));
      onClose();
    },
    onError: (err) => {
      // Whatever happened on the way, show what is really saved now.
      refresh();
      setServerError(errorCodeOf(err) === 'PAYROLL_SECTION_CHANGED' ? t('notify.payroll.sectionChanged') : draftFailureMessage(err, t('notify.payroll.draftFailed')));
    },
  });

  return (
    <Modal
      isOpen
      onClose={mutation.isPending ? () => undefined : onClose}
      title={t('confirm.payroll.generateCompanyTitle', { name, month: monthLabel })}
      size="md"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>{t('action.cancel')}</Button>
          <Button size="sm" isLoading={mutation.isPending} onClick={() => { setServerError(null); mutation.mutate(); }} data-testid="generate-company-confirm">
            {t('action.generateDraft')}
          </Button>
        </>
      }
    >
      {serverError && <div role="alert" className="mb-4 rounded-md border border-danger/30 bg-danger-soft px-3.5 py-2.5 text-sm text-danger">{serverError}</div>}
      <p className="text-sm text-ink-muted">{t('confirm.payroll.generateCompanyBody', { name, month: monthLabel })}</p>
      {generateResetsReview(row) && (
        <p className="mt-3 rounded-sm bg-warning-soft px-3 py-2 text-sm text-ink" data-testid="generate-resets-review">{t('confirm.payroll.generateCompanyReviewedNote')}</p>
      )}
      <GenerationWarningsPanel period={period} company={row.client_company_id} context="generate" />
    </Modal>
  );
}
