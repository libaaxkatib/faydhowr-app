import { useEffect, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { complianceApi } from '@/api/compliance';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { ErrorState } from '@/components/ui/ErrorState';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { LoadingState } from '@/components/ui/LoadingState';
import { Modal } from '@/components/ui/Modal';
import { PageHeader } from '@/components/ui/PageHeader';
import { Pagination } from '@/components/ui/Pagination';
import { SearchInput } from '@/components/ui/SearchInput';
import { Select } from '@/components/ui/Select';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { TableScroll } from '@/components/ui/TableScroll';
import { useToast } from '@/components/ui/useToast';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import type { MessageKey } from '@/i18n/messages';
import { useI18n } from '@/i18n/useI18n';
import type { ComplianceFilters, ComplianceFinding, ComplianceStatus, ComplianceSummary } from '@/types/compliance';
import { canSave, confidenceTone, EMPTY_FILTERS, severityTone } from '@/utils/compliance';
import { formatDate } from '@/utils/formatters';

const FILTER_KEYS = ['q', 'company', 'responsible', 'type', 'category', 'severity', 'status', 'confidence'] as const;
const STATUSES: ComplianceStatus[] = ['open', 'in_progress', 'resolved'];

const SUMMARY: { key: keyof ComplianceSummary; messageKey: MessageKey }[] = [
  { key: 'active_employees_reviewed', messageKey: 'option.compliance.summaryReviewed' },
  { key: 'incomplete_profiles', messageKey: 'option.compliance.summaryProfiles' },
  { key: 'unmet_job_requirements', messageKey: 'option.compliance.summaryRequirements' },
  { key: 'incomplete_workflows', messageKey: 'option.compliance.summaryWorkflows' },
  { key: 'needs_hr_review', messageKey: 'option.compliance.summaryReview' },
  { key: 'open', messageKey: 'option.compliance.summaryOpen' },
  { key: 'in_progress', messageKey: 'option.compliance.summaryProgress' },
  { key: 'resolved', messageKey: 'option.compliance.summaryResolved' },
];

/**
 * HR compliance and accountability report (HR Manager and Super Admin). It lists who works, or is assigned to work, while a requirement the system already
 * defines is not met, whether the finding is confirmed or only needs HR review, who is on record as responsible (never guessed), and lets the HR Manager track
 * the follow-up. Opening the report only reads; saving a follow-up changes the finding's status and note and nothing about the employee, assignment or payroll.
 */
export function ComplianceReportPage() {
  const { t } = useI18n();
  const url = useUrlFilters();
  const [search, setSearch] = useState(url.get('q'));
  const [followUp, setFollowUp] = useState<ComplianceFinding | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => url.set('q', search.trim()), 400);

    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search]);

  const filters: ComplianceFilters = {
    ...EMPTY_FILTERS,
    search: url.get('q'),
    client_company_id: url.get('company'),
    responsible_admin_id: url.get('responsible'),
    employment_type: url.get('type'),
    category: url.get('category'),
    severity: url.get('severity'),
    status: url.get('status'),
    confidence: url.get('confidence'),
  };

  const report = useQuery({
    queryKey: ['hr', 'compliance', filters, url.page],
    queryFn: () => complianceApi.list(filters, url.page),
    placeholderData: keepPreviousData,
  });

  const data = report.data?.data;
  const activeFilters = url.activeCount(FILTER_KEYS);

  const choice = (key: string, value: string, label: MessageKey, options: { value: string; label: string }[]) => (
    <Select aria-label={t(label)} value={value} onChange={(event) => url.set(key, event.target.value)} placeholder={t(label)} options={options} />
  );

  return (
    <div>
      <PageHeader title={t('option.compliance.title')} breadcrumb={[{ label: t('nav.complianceReport') }]} />

      <Card className="mb-4 p-4">
        <p className="text-sm text-ink-muted">{t('warning.compliance.intro')}</p>
      </Card>

      {report.isLoading && <LoadingState />}
      {report.isError && <ErrorState error={report.error} onRetry={() => void report.refetch()} />}

      {data && (
        <>
          <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4" data-testid="compliance-summary">
            {SUMMARY.map((item) => (
              <Card key={item.key} className="p-3">
                <p className="text-xs text-ink-muted">{t(item.messageKey)}</p>
                <p className="font-display text-2xl font-bold text-ink" data-testid={`summary-${item.key}`}>{data.summary[item.key]}</p>
              </Card>
            ))}
          </div>

          <Card className="mb-4 p-4">
            <div className="grid gap-3 md:grid-cols-4">
              <SearchInput value={search} onChange={setSearch} placeholder={t('option.compliance.filterSearch')} />
              {choice('company', filters.client_company_id, 'option.compliance.allCompanies', data.filter_options.companies.map((c) => ({ value: String(c.id), label: c.name })))}
              {choice('responsible', filters.responsible_admin_id, 'option.compliance.allResponsible', [{ value: 'none', label: t('option.compliance.responsibleNone') }, ...data.filter_options.responsible.map((r) => ({ value: String(r.id), label: r.name }))])}
              {choice('type', filters.employment_type, 'option.compliance.allTypes', (['Permanent', 'Temporary', 'Replacement'] as const).map((v) => ({ value: v, label: v === 'Replacement' ? t('option.assignmentType.replacement') : t(`option.employmentType.${v.toLowerCase() as 'permanent' | 'temporary'}`) })))}
              {choice('category', filters.category, 'option.compliance.allCategories', (['profile', 'job_requirements', 'workflow'] as const).map((v) => ({ value: v, label: t(`option.compliance.category.${v}`) })))}
              {choice('severity', filters.severity, 'option.compliance.allSeverities', (['high', 'medium', 'low'] as const).map((v) => ({ value: v, label: t(`option.compliance.severity.${v}`) })))}
              {choice('status', filters.status, 'option.compliance.allStatuses', STATUSES.map((v) => ({ value: v, label: t(`option.compliance.status.${v}`) })))}
              {choice('confidence', filters.confidence, 'option.compliance.allConfidence', (['confirmed', 'needs_review'] as const).map((v) => ({ value: v, label: t(`option.compliance.confidence.${v}`) })))}
            </div>
            {activeFilters > 0 && (
              <Button variant="outline" size="sm" className="mt-3" onClick={() => { setSearch(''); url.reset(FILTER_KEYS); }}>
                {t('action.compliance.clear')}
              </Button>
            )}
          </Card>

          <Card className="p-4">
            {data.findings.length === 0 ? (
              <p className="py-6 text-center text-sm text-ink-muted">{t('warning.compliance.noFindings')}</p>
            ) : (
              <TableScroll>
                <table className="w-full text-left text-sm" data-testid="compliance-table">
                  <thead>
                    <tr className="text-xs uppercase text-ink-muted">
                      <th className="px-3 py-2">{t('option.compliance.colEmployee')}</th>
                      <th className="px-3 py-2">{t('option.compliance.colAssignment')}</th>
                      <th className="px-3 py-2">{t('option.compliance.colIssue')}</th>
                      <th className="px-3 py-2">{t('option.compliance.colAccountability')}</th>
                      <th className="px-3 py-2">{t('option.compliance.colAction')}</th>
                      <th className="px-3 py-2">{t('option.compliance.colStatus')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.findings.map((f) => (
                      <FindingRow key={f.id} finding={f} onFollowUp={() => setFollowUp(f)} />
                    ))}
                  </tbody>
                </table>
              </TableScroll>
            )}
            {report.data?.meta && <div className="mt-3"><Pagination meta={report.data.meta} onPageChange={url.setPage} /></div>}
          </Card>
        </>
      )}

      {followUp && <FollowUpDialog finding={followUp} onClose={() => setFollowUp(null)} />}
    </div>
  );
}

function typeLabel(t: ReturnType<typeof useI18n>['t'], type: ComplianceFinding['employment_type']): string {
  return type === 'Replacement' ? t('option.assignmentType.replacement') : t(`option.employmentType.${type.toLowerCase() as 'permanent' | 'temporary'}`);
}

function FindingRow({ finding: f, onFollowUp }: { finding: ComplianceFinding; onFollowUp: () => void }) {
  const { t, tValue } = useI18n();

  return (
    <tr className="border-t border-border align-top" data-testid={`finding-${f.id}`}>
      <td className="px-3 py-3">
        <p className="font-medium text-ink">{f.employee_name}</p>
        <p className="text-xs text-ink-faint">{f.employee_number}</p>
      </td>
      <td className="px-3 py-3">
        <p>{f.company ?? '—'}</p>
        <p className="text-xs text-ink-muted">{[f.position, f.shift].filter(Boolean).join(' · ') || '—'}</p>
        <p className="mt-1 flex flex-wrap gap-1">
          <StatusBadge status={f.employment_type} tone="neutral" label={typeLabel(t, f.employment_type)} />
          <StatusBadge status={f.employment_status} label={tValue('status', f.employment_status) ?? f.employment_status} />
          {f.assignment_status && <StatusBadge status={f.assignment_status} label={tValue('status', f.assignment_status) ?? f.assignment_status} />}
        </p>
      </td>
      <td className="px-3 py-3 min-w-64">
        <p className="mb-1 flex flex-wrap gap-1">
          <StatusBadge status={f.category} tone="neutral" label={t(`option.compliance.category.${f.category}`)} />
          <StatusBadge status={f.severity} tone={severityTone(f.severity)} label={t(`option.compliance.severity.${f.severity}`)} />
          <StatusBadge status={f.confidence} tone={confidenceTone(f.confidence)} label={t(`option.compliance.confidence.${f.confidence}`)} />
        </p>
        <p className="font-medium text-ink">{f.requirement}</p>
        <p className="mt-1 text-xs text-ink-muted">{f.explanation}</p>
        {f.confidence === 'needs_review' && <p className="mt-1 text-xs text-warning">{t('warning.compliance.needsReviewNote')}</p>}
      </td>
      <td className="px-3 py-3">
        {f.responsible ? (
          <p><span className="font-medium text-ink">{f.responsible.name}</span><span className="block text-xs text-ink-muted">{[f.responsible.action, f.responsible.at ? formatDate(f.responsible.at) : null].filter(Boolean).join(' · ')}</span></p>
        ) : (
          <p className="text-xs italic text-ink-muted">{t('warning.compliance.responsibleNotRecorded')}</p>
        )}
        <p className="mt-2 text-xs text-ink-muted">{t('option.compliance.appointedOn')}: {f.appointed_on ? formatDate(f.appointed_on) : '—'}</p>
        <p className="text-xs text-ink-muted">{t('option.compliance.detectedOn')}: {f.detected_on ? formatDate(f.detected_on) : '—'}</p>
      </td>
      <td className="px-3 py-3 min-w-48 text-ink">{f.recommended_action}</td>
      <td className="px-3 py-3">
        <StatusBadge status={f.status} tone={f.status === 'resolved' ? 'success' : f.status === 'in_progress' ? 'info' : 'warning'} label={t(`option.compliance.status.${f.status}`)} />
        {f.resolution_source === 'system_cleared' && <p className="mt-1 text-xs text-ink-muted">{t('warning.compliance.systemCleared')}</p>}
        {f.note && <p className="mt-1 max-w-48 text-xs text-ink-muted">{f.note}</p>}
        <Button variant="outline" size="sm" className="mt-2" onClick={onFollowUp}>{t('action.compliance.followUp')}</Button>
      </td>
    </tr>
  );
}

function FollowUpDialog({ finding, onClose }: { finding: ComplianceFinding; onClose: () => void }) {
  const { t } = useI18n();
  const { show } = useToast();
  const queryClient = useQueryClient();
  const [status, setStatus] = useState<ComplianceStatus>(finding.status);
  const [note, setNote] = useState('');

  const detail = useQuery({ queryKey: ['hr', 'compliance', 'finding', finding.id], queryFn: () => complianceApi.show(finding.id) });

  const save = useMutation({
    mutationFn: () => complianceApi.update(finding.id, { status, note }),
    onSuccess: async () => {
      show(t('notify.compliance.saved'));
      await queryClient.invalidateQueries({ queryKey: ['hr', 'compliance'] });
      onClose();
    },
    onError: () => show(t('notify.compliance.saveFailed'), 'error'),
  });

  const allowed = canSave(status, note, finding.note);

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('option.compliance.dialogTitle')}
      size="lg"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
          <Button size="sm" onClick={() => save.mutate()} isLoading={save.isPending} disabled={!allowed}>{t('action.compliance.save')}</Button>
        </>
      }
    >
      <p className="mb-1 font-medium text-ink">{finding.employee_name} ({finding.employee_number})</p>
      <p className="mb-3 text-sm text-ink-muted">{finding.requirement}</p>
      <p className="mb-3 text-xs text-ink-faint">{t('option.compliance.noChangesToEmployee')}</p>

      <FormField label={t('option.compliance.statusField')} htmlFor="compliance-status">
        <Select id="compliance-status" value={status} onChange={(event) => setStatus(event.target.value as ComplianceStatus)} options={STATUSES.map((s) => ({ value: s, label: t(`option.compliance.status.${s}`) }))} />
      </FormField>
      <FormField label={t('option.compliance.noteField')} htmlFor="compliance-note" error={!allowed ? t('validation.compliance.noteRequired') : undefined}>
        <textarea id="compliance-note" rows={3} maxLength={1000} className={`${inputClasses} h-auto py-2`} value={note} onChange={(event) => setNote(event.target.value)} />
      </FormField>
      {finding.note && <p className="mb-3 text-xs text-ink-muted">{t('option.compliance.note')}: {finding.note}</p>}

      <h3 className="mb-1 text-sm font-semibold text-ink">{t('option.compliance.history')}</h3>
      {detail.data?.history && detail.data.history.length === 0 && <p className="text-xs text-ink-muted">{t('option.compliance.noHistory')}</p>}
      <ul className="space-y-1 text-xs text-ink-muted">
        {(detail.data?.history ?? []).map((h, index) => (
          <li key={`${h.at}-${index}`}>
            {h.at ? formatDate(h.at) : ''} — {h.by}: {h.from_status ?? ''} → {h.to_status ?? ''}{h.note ? ` — ${h.note}` : ''}
          </li>
        ))}
      </ul>
    </Modal>
  );
}
