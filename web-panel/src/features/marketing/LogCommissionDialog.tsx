import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { ApiClientError } from '@/api/client';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/useToast';
import { useAuth } from '@/features/auth/useAuth';
import { useI18n } from '@/i18n/useI18n';
import { canRecordCommissionAmounts } from '@/utils/marketingAccess';
import { invalidateMarketing } from '@/utils/marketingCache';
import { marketingErrorMessage } from '@/utils/marketingErrors';
import { validateCommissionEntry } from '@/utils/marketingFormRules';
import { localIsoDate } from '@/utils/marketingReportRange';

interface LogCommissionDialogProps {
  isOpen: boolean;
  onClose: () => void;
  recordId: number;
  recordNumber: string;
}

/**
 * "Log commission": a person records, by hand, which employee brought this XARUN / PROJECT. It sends exactly what the server's manual-entry endpoint
 * accepts (employee, reference date, optional note and - for the Super Admin and the Marketing Manager only - an optional agreed amount). Nothing is
 * calculated, no rate is applied and nobody is credited automatically: the employee is chosen here.
 */
export function LogCommissionDialog({ isOpen, onClose, recordId, recordNumber }: LogCommissionDialogProps) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t } = useI18n();
  const { admin, isSuperAdmin } = useAuth();
  const mayEnterAmount = canRecordCommissionAmounts({ isSuperAdmin, role: admin?.role });
  const [form, setForm] = useState({ admin_id: '', reference_date: '', amount: '', notes: '' });
  const [clientErrors, setClientErrors] = useState<Record<string, string>>({});
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});

  const { data: employees } = useQuery({ queryKey: ['marketing-employees'], queryFn: marketingApi.employees.list, enabled: isOpen });

  useEffect(() => {
    if (isOpen) {
      setForm({ admin_id: '', reference_date: localIsoDate(new Date()), amount: '', notes: '' });
      setClientErrors({});
      setServerErrors({});
    }
  }, [isOpen]);

  const mutation = useMutation({
    mutationFn: () =>
      marketingApi.commission.createRecord(recordId, {
        admin_id: Number(form.admin_id),
        reference_date: form.reference_date,
        notes: form.notes.trim() === '' ? null : form.notes.trim(),
        ...(mayEnterAmount && form.amount.trim() !== '' ? { amount: Number(form.amount) } : {}),
      }),
    onSuccess: () => {
      invalidateMarketing(queryClient);
      show(t('notify.marketing.commissionLogged'));
      onClose();
    },
    onError: (error) => {
      if (error instanceof ApiClientError && error.isValidation) setServerErrors(error.fieldErrors ?? {});
      else show(marketingErrorMessage(error, t('notify.marketing.somethingWentWrong')), 'error');
    },
  });

  function handleSubmit(event: FormEvent) {
    event.preventDefault();
    const errors = validateCommissionEntry({ ...form, amount: mayEnterAmount ? form.amount : '' });
    setClientErrors(errors);
    setServerErrors({});
    if (Object.keys(errors).length === 0) mutation.mutate();
  }

  const error = (field: string) => clientErrors[field] ?? serverErrors[field]?.[0];

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={`${t('confirm.marketing.logCommissionTitle')} — ${recordNumber}`}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>{t('action.cancel')}</Button>
          <Button size="sm" onClick={handleSubmit} isLoading={mutation.isPending}>{t('action.marketing.logCommission')}</Button>
        </>
      }
    >
      <form onSubmit={handleSubmit} noValidate>
        <p className="mb-3 text-xs text-ink-muted">{t('option.marketing.logCommissionHint')}</p>
        <FormField label={t('option.marketing.commissionEmployee')} htmlFor="commission-log-employee" required error={error('admin_id')}>
          <Select
            id="commission-log-employee"
            value={form.admin_id}
            onChange={(e) => setForm({ ...form, admin_id: e.target.value })}
            placeholder={t('option.marketing.chooseEmployee')}
            options={(employees ?? []).map((employee) => ({ value: String(employee.id), label: employee.full_name }))}
          />
        </FormField>
        <FormField label={t('option.marketing.referenceDate')} htmlFor="commission-log-date" required error={error('reference_date')}>
          <input id="commission-log-date" type="date" className={inputClasses} value={form.reference_date} onChange={(e) => setForm({ ...form, reference_date: e.target.value })} />
        </FormField>
        {mayEnterAmount && (
          <FormField label={t('option.marketing.commissionAmountOptional')} htmlFor="commission-log-amount" hint={t('warning.marketing.agreedAmountHint')} error={error('amount')}>
            <input id="commission-log-amount" type="number" min="0" step="0.01" className={inputClasses} value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
          </FormField>
        )}
        <FormField label={t('option.marketing.note')} htmlFor="commission-log-note" error={error('notes')}>
          <input id="commission-log-note" type="text" className={inputClasses} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        </FormField>
      </form>
    </Modal>
  );
}
