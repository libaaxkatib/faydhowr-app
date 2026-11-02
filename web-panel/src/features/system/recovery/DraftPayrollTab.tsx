import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';

import { recoveryApi } from '@/api/recovery';
import { Card } from '@/components/ui/Card';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { useI18n } from '@/i18n/useI18n';

import { ImpactTable } from './ImpactTable';

/** Read-only: what a month's payroll would be if it were generated right now, against the stored draft. Nothing is saved. */
export function DraftPayrollTab() {
  const { t } = useI18n();
  const [month, setMonth] = useState('');
  const [employeeId, setEmployeeId] = useState('');

  const show = useMutation({
    mutationFn: () => recoveryApi.payrollPreview(month, employeeId.trim() === '' ? undefined : Number(employeeId)),
  });

  const valid = /^\d{4}-(0[1-9]|1[0-2])$/.test(month) && (employeeId.trim() === '' || /^\d+$/.test(employeeId.trim()));

  return (
    <Card className="p-4">
      <p className="mb-3 text-sm text-ink-muted">{t('warning.recovery.payrollPreviewNote')}</p>
      <div className="grid gap-x-4 md:grid-cols-3">
        <FormField label={t('option.recovery.month2')} htmlFor="recovery-payroll-month" required>
          <input id="recovery-payroll-month" type="month" className={inputClasses} value={month} onChange={(event) => setMonth(event.target.value)} />
        </FormField>
        <FormField label={t('option.recovery.employeeId')} htmlFor="recovery-payroll-employee">
          <input id="recovery-payroll-employee" inputMode="numeric" className={inputClasses} value={employeeId} onChange={(event) => setEmployeeId(event.target.value)} />
        </FormField>
      </div>
      <button
        type="button"
        disabled={!valid || show.isPending}
        onClick={() => show.mutate()}
        className="mb-4 inline-flex h-10 items-center rounded-sm bg-primary px-4 text-sm font-semibold text-white transition hover:bg-primary-ink disabled:cursor-not-allowed disabled:bg-primary/50"
      >
        {t('action.recovery.showPayroll')}
      </button>

      {show.data && (
        <div>
          <p className="mb-2 text-sm text-ink">
            {t('option.recovery.runStatus')}: <strong>{show.data.run_status ?? t('option.recovery.noRun')}</strong>
          </p>
          <ImpactTable period={show.data.month} comparison={show.data.comparison} currency="USD" />
          <p className="text-sm text-ink-muted">{show.data.guidance}</p>
        </div>
      )}
    </Card>
  );
}
