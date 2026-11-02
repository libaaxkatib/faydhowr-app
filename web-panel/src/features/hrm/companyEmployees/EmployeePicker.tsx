import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { companyEmployeesApi } from '@/api/companyEmployees';
import { hrApi } from '@/api/hr';
import { Button } from '@/components/ui/Button';
import { inputClasses } from '@/components/ui/FormField';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useI18n } from '@/i18n/useI18n';
import type { CompanyEmployeeCandidate } from '@/types/companyEmployees';
import { friendlyErrorMessage } from '@/utils/hrHelpers';

interface EmployeePickerProps {
  companyId: number;
  /** A registered employee is chosen by their unique id; the name alone is never what is submitted. */
  value: CompanyEmployeeCandidate | null;
  onChange: (candidate: CompanyEmployeeCandidate | null) => void;
  label: string;
  error?: string;
  /** Employee ids that cannot be chosen here (for example the person being replaced). */
  excludeIds?: number[];
  inputId: string;
}

/**
 * Search the existing HR register by employee number, Employee ID, name or phone. Every result shows the identifiers (employee number,
 * Employee ID, status, phone, category, current companies), so people with the same name are told apart. Nobody is created here: if the
 * person is not in the list, their HR record has to be registered first.
 */
export function EmployeePicker({ companyId, value, onChange, label, error, excludeIds = [], inputId }: EmployeePickerProps) {
  const { t, tValue } = useI18n();
  const queryClient = useQueryClient();
  const [term, setTerm] = useState('');
  // Activate in place: the person being confirmed, the last result, and any error. Nothing here touches the form that holds this picker.
  const [activating, setActivating] = useState<CompanyEmployeeCandidate | null>(null);
  const [activated, setActivated] = useState<string | null>(null);
  const [activateError, setActivateError] = useState<string | null>(null);
  const activate = useMutation({
    mutationFn: (candidate: CompanyEmployeeCandidate) => (candidate.activation === 'rehire' ? hrApi.employees.rehire(candidate.id) : hrApi.employees.updateStatus(candidate.id, 'active')),
    onSuccess: (_employee, candidate) => {
      setActivating(null);
      setActivateError(null);
      setActivated(t('notify.company.employeeActivated', { name: candidate.full_name }));
      // Eligibility changed: the search result is read again, the rest of the form is left exactly as it is.
      void queryClient.invalidateQueries({ queryKey: ['company-employee-candidates', companyId] });
    },
    onError: (error) => setActivateError(friendlyErrorMessage(error, t('notify.company.couldNotActivate'))),
  });
  const debounced = useDebouncedValue(term.trim(), 300);
  const enabled = debounced.length >= 2;

  const { data, isFetching, error: loadError } = useQuery({
    queryKey: ['company-employee-candidates', companyId, debounced],
    queryFn: () => companyEmployeesApi.candidates(companyId, debounced),
    enabled,
    // Eligibility changes the moment someone is assigned, so a result is never reused from the cache.
    gcTime: 0,
    staleTime: 0,
  });

  if (value !== null) {
    return (
      <div className="mb-4">
        <p className="mb-1.5 text-sm font-medium text-ink">{label}</p>
        <div className="flex items-start justify-between gap-3 rounded-md border border-border bg-surface-alt px-3.5 py-3" data-testid="picked-employee">
          <div>
            <p className="text-sm font-semibold text-ink">{value.full_name}</p>
            <p className="text-xs text-ink-muted">
              {value.employee_number} · {t('option.label.employeeId')} {value.id} · {tValue('status', value.status) ?? value.status}
              {value.phone ? ` · ${value.phone}` : ''}
            </p>
            {value.current_companies.length > 0 && <p className="text-xs text-ink-faint">{t('option.company.currentlyAt')} {value.current_companies.join(', ')}</p>}
          </div>
          <button type="button" className="text-xs font-semibold text-primary hover:underline" onClick={() => onChange(null)}>
            {t('action.company.changePicked')}
          </button>
        </div>
        {error && <p className="mt-1 text-xs text-danger">{error}</p>}
      </div>
    );
  }

  const results = (data ?? []).filter((candidate) => !excludeIds.includes(candidate.id));

  return (
    <div className="mb-4">
      <label htmlFor={inputId} className="mb-1.5 block text-sm font-medium text-ink">
        {label} <span className="text-danger">*</span>
      </label>
      <input
        id={inputId}
        className={inputClasses}
        value={term}
        onChange={(event) => setTerm(event.target.value)}
        placeholder={t('option.company.pickerPlaceholder')}
        autoComplete="off"
        aria-describedby={`${inputId}-hint`}
      />
      <p id={`${inputId}-hint`} className="mt-1 text-xs text-ink-faint">
        {t('confirm.company.pickerHint')}
      </p>
      {error && <p className="mt-1 text-xs text-danger">{error}</p>}
      {loadError && <p className="mt-1 text-xs text-danger">{t('notify.company.couldNotSearchEmployees')}</p>}
      {enabled && (
        <div className="mt-2 max-h-64 overflow-y-auto rounded-md border border-border" role="listbox" aria-label={t('option.company.matchingEmployees')}>
          {isFetching && results.length === 0 && <p className="px-3.5 py-3 text-xs text-ink-muted">{t('option.label.searching')}</p>}
          {!isFetching && results.length === 0 && (
            <p className="px-3.5 py-3 text-xs text-ink-muted">{t('confirm.company.pickerNoMatch')}</p>
          )}
          {results.map((candidate) => (
            <div key={candidate.id} className="border-b border-border last:border-b-0" data-testid={`candidate-${candidate.id}`}>
              <button
                type="button"
                role="option"
                aria-selected={false}
                disabled={!candidate.eligible}
                onClick={() => onChange(candidate)}
                className="block w-full px-3.5 py-2.5 text-left enabled:hover:bg-surface-alt disabled:cursor-not-allowed disabled:opacity-60"
              >
                <span className="block text-sm font-medium text-ink">{candidate.full_name}</span>
                <span className="block text-xs text-ink-muted">
                  {candidate.employee_number} · {t('option.label.employeeId')} {candidate.id} · {tValue('status', candidate.status) ?? candidate.status}
                  {candidate.phone ? ` · ${candidate.phone}` : ''}
                  {candidate.category ? ` · ${candidate.category}` : ''}
                </span>
                {candidate.current_companies.length > 0 && <span className="block text-xs text-ink-faint">{t('option.company.currentlyAt')} {candidate.current_companies.join(', ')}</span>}
                {!candidate.eligible && <span className="block text-xs font-medium text-warning">{t('confirm.company.pickerCannotChoose', { reason: candidate.ineligible_reason ?? '' })}</span>}
              </button>
              {!candidate.eligible && candidate.can_activate && activating?.id !== candidate.id && (
                <div className="px-3.5 pb-2.5">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => {
                      setActivating(candidate);
                      setActivated(null);
                      setActivateError(null);
                    }}
                    aria-label={`${t('action.company.activate')} ${candidate.full_name}`}
                  >
                    {t('action.company.activate')}
                  </Button>
                </div>
              )}
              {activating?.id === candidate.id && (
                <div role="alertdialog" aria-label={t('confirm.company.activateTitle', { name: candidate.full_name })} className="mx-3.5 mb-2.5 rounded-md border border-border bg-surface-alt px-3.5 py-3 text-xs text-ink" data-testid="activate-confirm">
                  <p className="mb-1 font-semibold">{t('confirm.company.activateTitle', { name: candidate.full_name })}</p>
                  <p className="mb-2 text-ink-muted">{t(candidate.activation === 'rehire' ? 'confirm.company.activateBodyRehire' : 'confirm.company.activateBodyStatus', { name: candidate.full_name })}</p>
                  {candidate.activation === 'rehire' && candidate.rehire_eligible === false && <p className="mb-2 font-medium text-warning">{t('warning.company.rehireNotRecommended')}</p>}
                  {activateError && <p role="alert" className="mb-2 text-danger">{activateError}</p>}
                  <div className="flex gap-2">
                    <Button type="button" size="sm" onClick={() => activate.mutate(candidate)} isLoading={activate.isPending}>{t('action.company.activateConfirm')}</Button>
                    <Button type="button" variant="outline" size="sm" onClick={() => setActivating(null)} disabled={activate.isPending}>{t('action.cancel')}</Button>
                  </div>
                </div>
              )}
            </div>
          ))}
        </div>
      )}
      {activated && (
        <p role="status" className="mt-2 text-xs font-medium text-success" data-testid="activated-note">
          {activated}
        </p>
      )}
    </div>
  );
}
