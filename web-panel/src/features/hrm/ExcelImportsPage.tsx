import { useCallback, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';

import { hrImportsApi } from '@/api/hrImports';
import { ApiClientError } from '@/api/client';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable } from '@/components/ui/DataTable';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Pagination } from '@/components/ui/Pagination';
import { Select } from '@/components/ui/Select';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { useToast } from '@/components/ui/useToast';
import type { MessageKey } from '@/i18n/messages';
import { Msg } from '@/i18n/Msg';
import { getActiveLanguage } from '@/i18n/active';
import { useI18n } from '@/i18n/useI18n';
import { formatDateTime } from '@/utils/formatters';
import {
  ACTIVATION_PATH_TEXT,
  BATCH_STATUS,
  batchStage,
  canCancel,
  canConfirm,
  checkImportFile,
  daysUntilDeletion,
  describeImportError,
  IMPORT_TYPES,
  importTypeInfo,
  progressPercent,
  ROW_STATUS,
  rowCodeText,
} from '@/utils/hrImports';
import type { ImportBatch, ImportRow, ImportRowStatus, ImportType } from '@/types/hrImport';

const FILTERS: { value: ImportRowStatus | ''; labelKey: MessageKey }[] = [
  { value: '', labelKey: 'option.importRow.all' },
  { value: 'valid', labelKey: 'option.importRow.valid' },
  { value: 'review', labelKey: 'option.importRow.review' },
  { value: 'duplicate', labelKey: 'option.importRow.duplicate' },
  { value: 'invalid', labelKey: 'option.importRow.invalid' },
  { value: 'imported', labelKey: 'option.importRow.imported' },
  { value: 'skipped', labelKey: 'option.importRow.skipped' },
  { value: 'failed', labelKey: 'option.importRow.failed' },
];

type Translate = ReturnType<typeof useI18n>['t'];

/** The import type's name in Somali; in English the server's own `type_label` is shown, as before. */
function batchTypeLabel(batch: Pick<ImportBatch, 'type' | 'type_label'>): string {
  if (getActiveLanguage() !== 'so') return batch.type_label;
  return IMPORT_TYPES.find((info) => info.value === batch.type)?.label ?? batch.type_label;
}

function errorText(error: unknown, fallback: string): string {
  if (error instanceof ApiClientError) return describeImportError(error.code, error.message);
  return error instanceof Error ? error.message : fallback;
}

/** The few sheet values worth showing in the preview (the rest stays in the sheet). */
function rowDetails(row: ImportRow, t: Translate): string {
  const d = row.details ?? {};
  const report = (row.meta?.report ?? null) as Record<string, string> | null;

  if (report) {
    const path = ACTIVATION_PATH_TEXT[report.proposed_path] ?? report.proposed_path;
    return [report.employee_number, report.current_status && t('option.imports.detailStatusToActive', { status: report.current_status }), path].filter(Boolean).join(' · ');
  }

  const name = (d.full_name ?? d.name) as string | undefined;
  const parts = [name, d.phone as string | undefined, d.category as string | undefined, d.gender as string | undefined].filter(Boolean);
  const candidates = (row.meta?.employee_numbers ?? null) as string[] | null;
  const suffix = candidates && candidates.length > 0 ? ` ${t('option.imports.detailCandidates', { list: candidates.join(', ') })}` : '';
  return parts.length > 0 ? parts.join(' · ') + suffix : suffix.trim();
}

export function ExcelImportsPage() {
  const { t } = useI18n();
  const { show } = useToast();
  const queryClient = useQueryClient();
  const fileInput = useRef<HTMLInputElement>(null);

  const [type, setType] = useState<ImportType>('waiting_list');
  const [file, setFile] = useState<File | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [historyPage, setHistoryPage] = useState(1);
  const typeInfo = importTypeInfo(type);

  const history = useQuery({ queryKey: ['hr-imports', historyPage], queryFn: () => hrImportsApi.list(historyPage) });

  const uploadMutation = useMutation({
    mutationFn: () => {
      if (!file) throw new Error(t('notify.imports.chooseFileFirst'));
      const problem = checkImportFile(file);
      if (problem) throw new Error(problem);
      return hrImportsApi.upload(type, file);
    },
    onSuccess: (batch) => {
      setFile(null);
      if (fileInput.current) fileInput.current.value = '';
      setSelectedId(batch.id);
      queryClient.invalidateQueries({ queryKey: ['hr-imports'] });
      show(t('notify.imports.fileChecked'));
    },
    onError: (error) => {
      if (error instanceof ApiClientError && error.code === 'DUPLICATE_IMPORT') {
        const existing = (error.fieldErrors as unknown as { existing_batch_id?: number } | undefined)?.existing_batch_id;
        if (existing) setSelectedId(existing);
      }
      show(errorText(error, t('notify.imports.somethingWrong')), 'error');
    },
  });

  const historyColumns: ColumnDef<ImportBatch, unknown>[] = [
    { header: '#', accessorKey: 'id' },
    { header: t('option.label.type'), accessorKey: 'type_label', cell: ({ row }) => batchTypeLabel(row.original) },
    {
      header: t('option.label.status'),
      cell: ({ row }) => <StatusBadge status={row.original.status} label={BATCH_STATUS[row.original.status].label} tone={BATCH_STATUS[row.original.status].tone} />,
    },
    {
      header: t('option.imports.colRows'),
      cell: ({ row }) => {
        const c = row.original.counts;
        return t('option.imports.historyRows', { total: c.total, imported: c.imported });
      },
    },
    { header: t('option.imports.colUploadedBy'), cell: ({ row }) => row.original.created_by ?? '—' },
    { header: t('option.imports.colWhen'), cell: ({ row }) => formatDateTime(row.original.created_at) },
    {
      header: '',
      id: 'open',
      cell: ({ row }) => (
        <Button size="sm" variant="outline" onClick={() => setSelectedId(row.original.id)}>
          {t('action.open')}
        </Button>
      ),
    },
  ];

  return (
    <div>
      <PageHeader title={t('nav.excelImports')} breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.excelImports') }]} />

      <Card className="mb-4">
        <CardHeader>
          <h3 className="font-display text-sm font-bold text-ink">{t('option.imports.uploadHeading')}</h3>
        </CardHeader>
        <CardBody className="space-y-4">
          <p className="text-sm text-ink-muted">
            <Msg id="warning.imports.uploadIntro" values={{ checks: <strong>{t('warning.imports.uploadIntroChecks')}</strong> }} />
          </p>

          <div className="grid gap-4 md:grid-cols-2">
            <FormField label={t('option.imports.whatImporting')} htmlFor="import-type">
              <Select
                id="import-type"
                value={type}
                onChange={(e) => setType(e.target.value as ImportType)}
                options={IMPORT_TYPES.map((info) => ({ value: info.value, label: info.label }))}
              />
            </FormField>
            <FormField label={t('option.imports.fileLabel')} htmlFor="import-file" hint={t('warning.imports.fileHint')}>
              <input
                id="import-file"
                ref={fileInput}
                type="file"
                accept=".xlsx,.csv"
                onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                className={inputClasses}
              />
            </FormField>
          </div>

          <div className="rounded-sm border border-border bg-surface-alt p-3 text-sm">
            <p className="text-ink">{typeInfo.summary}</p>
            <p className="mt-2 text-xs text-ink-muted">
              <strong>{t('option.imports.requiredColumns')}</strong> {typeInfo.required.join(', ')}
            </p>
            {typeInfo.optional.length > 0 && (
              <p className="mt-1 text-xs text-ink-muted">
                <strong>{t('option.imports.optionalColumns')}</strong> {typeInfo.optional.join(', ')}
              </p>
            )}
            {type === 'employee_registrations' && (
              <p className="mt-1 text-xs text-ink-muted">{t('warning.imports.registrationPaymentNote')}</p>
            )}
          </div>

          <div className="flex items-center gap-3">
            <Button isLoading={uploadMutation.isPending} disabled={!file} onClick={() => uploadMutation.mutate()}>
              <Icon name="file-text" size={15} />
              {t('action.checkFile')}
            </Button>
            {file && <span className="text-xs text-ink-muted">{file.name}</span>}
          </div>
        </CardBody>
      </Card>

      {selectedId !== null && <BatchPanel key={selectedId} id={selectedId} onClose={() => setSelectedId(null)} />}

      <Card className="mt-4">
        <CardHeader>
          <h3 className="font-display text-sm font-bold text-ink">{t('option.imports.historyHeading')}</h3>
        </CardHeader>
        <CardBody className="p-0">
          <DataTable
            columns={historyColumns}
            data={history.data?.data ?? []}
            isLoading={history.isLoading}
            error={history.error}
            onRetry={() => history.refetch()}
            emptyTitle={t('option.imports.historyEmptyTitle')}
            emptyDescription={t('option.imports.historyEmptyBody')}
          />
          {history.data && <Pagination meta={history.data.meta} onPageChange={setHistoryPage} />}
        </CardBody>
      </Card>
    </div>
  );
}

function Tile({ label, value, tone = 'neutral' }: { label: string; value: number; tone?: 'neutral' | 'success' | 'warning' | 'danger' }) {
  const color = { neutral: 'text-ink', success: 'text-success', warning: 'text-warning', danger: 'text-danger' }[tone];
  return (
    <div className="rounded-sm border border-border bg-surface p-3">
      <div className={`font-display text-xl font-bold ${color}`}>{value}</div>
      <div className="text-xs text-ink-muted">{label}</div>
    </div>
  );
}

function BatchPanel({ id, onClose }: { id: number; onClose: () => void }) {
  const { t, tCount } = useI18n();
  const { show } = useToast();
  const queryClient = useQueryClient();
  const [filter, setFilter] = useState<ImportRowStatus | ''>('');
  const [page, setPage] = useState(1);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [isRunning, setIsRunning] = useState(false);
  const running = useRef(false);

  const batchQuery = useQuery({ queryKey: ['hr-import', id], queryFn: () => hrImportsApi.get(id) });
  const rowsQuery = useQuery({
    queryKey: ['hr-import-rows', id, filter, page],
    queryFn: () => hrImportsApi.rows(id, { status: filter, page }),
    placeholderData: (previous) => previous,
  });

  const refreshAll = useCallback(() => {
    queryClient.invalidateQueries({ queryKey: ['hr-import', id] });
    queryClient.invalidateQueries({ queryKey: ['hr-import-rows', id] });
    queryClient.invalidateQueries({ queryKey: ['hr-imports'] });
  }, [queryClient, id]);

  /** Confirms once, then asks the server to import the next bounded set of rows until it reports completed. */
  const runImport = useCallback(async () => {
    if (running.current) return;
    running.current = true;
    setIsRunning(true);
    try {
      await hrImportsApi.confirm(id);
      let busyRetries = 0;
      for (let i = 0; i < 100; i++) {
        try {
          const batch = await hrImportsApi.process(id);
          queryClient.setQueryData(['hr-import', id], batch);
          if (batch.status === 'completed') {
            show(t('notify.imports.finished', { imported: batch.counts.imported, skipped: batch.counts.skipped, failed: batch.counts.failed }));
            break;
          }
        } catch (error) {
          if (error instanceof ApiClientError && error.code === 'IMPORT_BUSY' && busyRetries < 20) {
            busyRetries++;
            await new Promise((resolve) => setTimeout(resolve, 1500));
            continue;
          }
          throw error;
        }
      }
    } catch (error) {
      show(errorText(error, t('notify.imports.somethingWrong')), 'error');
    } finally {
      running.current = false;
      setIsRunning(false);
      refreshAll();
    }
  }, [id, queryClient, refreshAll, show, t]);

  const cancelMutation = useMutation({
    mutationFn: () => hrImportsApi.cancel(id),
    onSuccess: () => {
      setCancelOpen(false);
      show(t('notify.imports.cancelled'));
      refreshAll();
    },
    onError: (error) => show(errorText(error, t('notify.imports.somethingWrong')), 'error'),
  });

  const reportMutation = useMutation({
    mutationFn: () => hrImportsApi.downloadReport(id, filter),
    onError: (error) => show(errorText(error, t('notify.imports.somethingWrong')), 'error'),
  });

  const batch = batchQuery.data;

  if (batchQuery.isLoading || !batch) {
    return (
      <Card className="mb-4">
        <CardBody>{batchQuery.error ? <p className="text-sm text-danger">{errorText(batchQuery.error, t('notify.imports.somethingWrong'))}</p> : <p className="text-sm text-ink-muted">{t('option.label.loading')}</p>}</CardBody>
      </Card>
    );
  }

  const stage = batchStage(batch);
  const c = batch.counts;
  const days = daysUntilDeletion(batch.retention_expires_at);
  const progress = progressPercent(batch);

  const columns: ColumnDef<ImportRow, unknown>[] = [
    { header: t('option.imports.colRow'), accessorKey: 'row_number' },
    {
      header: t('option.imports.colResult'),
      cell: ({ row }) => <StatusBadge status={row.original.status} label={ROW_STATUS[row.original.status].label} tone={ROW_STATUS[row.original.status].tone} />,
    },
    { header: t('option.imports.colMeaning'), cell: ({ row }) => <span title={row.original.message ?? ''}>{rowCodeText(row.original.code) || row.original.message}</span> },
    { header: t('option.imports.colDetails'), cell: ({ row }) => <span className="text-xs text-ink-muted">{batch.details_deleted ? t('option.imports.detailsDeleted') : rowDetails(row.original, t)}</span> },
  ];

  return (
    <Card className="mb-4">
      <CardHeader className="flex-wrap">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="font-display text-base font-bold text-ink">
              {t('option.imports.batchTitle', { id: batch.id, type: batchTypeLabel(batch) })}
            </h3>
            <StatusBadge status={batch.status} label={BATCH_STATUS[batch.status].label} tone={BATCH_STATUS[batch.status].tone} />
          </div>
          <p className="mt-0.5 text-xs text-ink-muted">
            {batch.confirmed_at
              ? t('option.imports.batchUploadedConfirmed', { when: formatDateTime(batch.created_at), name: batch.created_by ?? '—', confirmed: formatDateTime(batch.confirmed_at) })
              : t('option.imports.batchUploaded', { when: formatDateTime(batch.created_at), name: batch.created_by ?? '—' })}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button size="sm" variant="outline" isLoading={reportMutation.isPending} onClick={() => reportMutation.mutate()}>
            <Icon name="file-text" size={14} />
            {t('action.imports.downloadReport')}
          </Button>
          {canCancel(batch) && (
            <Button size="sm" variant="outline" disabled={isRunning} onClick={() => setCancelOpen(true)}>
              {t('action.cancelImport')}
            </Button>
          )}
          {canConfirm(batch) && (
            <Button size="sm" isLoading={isRunning} onClick={() => setConfirmOpen(true)}>
              <Icon name="check" size={14} />
              {tCount('action.importRows', c.valid)}
            </Button>
          )}
          <Button size="sm" variant="ghost" onClick={onClose}>
            {t('action.close')}
          </Button>
        </div>
      </CardHeader>

      <CardBody className="space-y-4">
        {stage === 'preview' && (
          <div className="rounded-sm border border-border bg-secondary-soft p-3 text-sm text-ink">
            <strong>{t('warning.imports.previewOnlyTitle')}</strong> {t('warning.imports.previewOnlyBody')}
          </div>
        )}

        {batch.unrecognised_headers && batch.unrecognised_headers.length > 0 && (
          <p className="text-xs text-ink-muted">
            {t('option.imports.columnsNotImported', { list: batch.unrecognised_headers.join(', ') })}
          </p>
        )}

        <div className="grid grid-cols-2 gap-2 md:grid-cols-5">
          <Tile label={t('option.imports.tileTotal')} value={c.total} />
          <Tile label={t('option.importRow.valid')} value={c.valid} tone="success" />
          <Tile label={t('option.importRow.review')} value={c.review} tone="warning" />
          <Tile label={t('option.imports.tileDuplicate')} value={c.duplicate} />
          <Tile label={t('option.importRow.invalid')} value={c.invalid} tone="danger" />
        </div>

        {(stage === 'importing' || stage === 'finished') && (
          <div>
            <div className="mb-1 flex justify-between text-xs text-ink-muted">
              <span>
                {t('option.imports.progressCounts', { imported: c.imported, skipped: c.skipped, failed: c.failed })}
              </span>
              <span>{progress}%</span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-surface-alt" role="progressbar" aria-valuenow={progress} aria-valuemin={0} aria-valuemax={100}>
              <div className="h-full bg-primary transition-all" style={{ width: `${progress}%` }} />
            </div>
            {stage === 'importing' && !isRunning && (
              <div className="mt-2 flex items-center gap-2 text-xs text-ink-muted">
                <span>{t('warning.imports.stopped')}</span>
                <Button size="sm" variant="outline" onClick={() => void runImport()}>
                  {t('action.continueImport')}
                </Button>
              </div>
            )}
          </div>
        )}

        {batch.activation_ready !== undefined && (
          <div className="grid gap-2 md:grid-cols-2">
            <div className="rounded-sm border border-border p-3 text-sm">
              <p className="font-medium text-success">{t('option.imports.activationReady', { n: batch.activation_ready })}</p>
              <ul className="mt-1 space-y-0.5 text-ink-muted">
                {Object.entries(batch.activation_ready_by_path ?? {}).map(([path, count]) => (
                  <li key={path}>
                    {count} × {ACTIVATION_PATH_TEXT[path] ?? path}
                  </li>
                ))}
              </ul>
            </div>
            <div className="rounded-sm border border-border p-3 text-sm">
              <p className="font-medium text-warning">{t('option.imports.genuineConflicts', { n: batch.activation_excluded_total ?? 0 })}</p>
              <p className="mt-1 text-xs text-ink-muted">
                {t('warning.imports.genuineConflictsBody')}
              </p>
              {(batch.activation_lookalikes_noted ?? 0) > 0 && (
                <p className="mt-2 text-xs text-ink-muted">
                  {t('warning.imports.lookalikes', { n: batch.activation_lookalikes_noted ?? 0 })}
                </p>
              )}
            </div>
          </div>
        )}

        {batch.activation_paths && (
          <div className="rounded-sm border border-border p-3 text-sm">
            <p className="mb-1 font-medium text-ink">{t('option.imports.howBecomeActive')}</p>
            <ul className="space-y-0.5 text-ink-muted">
              {Object.entries(batch.activation_paths).map(([path, count]) => (
                <li key={path}>
                  {count} × {ACTIVATION_PATH_TEXT[path] ?? path}
                </li>
              ))}
            </ul>
            {batch.activation_warnings && Object.keys(batch.activation_warnings).length > 0 && (
              <p className="mt-2 text-xs text-warning">
                {t('option.imports.warningsList', { list: Object.entries(batch.activation_warnings).map(([w, n]) => `${n} × ${w.replace(/_/g, ' ').toLowerCase()}`).join(', ') })}
              </p>
            )}
          </div>
        )}

        <p className="text-xs text-ink-muted">
          {batch.details_deleted ? t('warning.imports.detailsDeleted') : days !== null ? tCount('warning.imports.deletedIn', days) : ''}
        </p>

        <div className="flex flex-wrap items-center gap-1">
          {FILTERS.map((f) => (
            <button
              key={f.value || 'all'}
              type="button"
              onClick={() => {
                setFilter(f.value);
                setPage(1);
              }}
              className={`rounded-full border px-3 py-1 text-xs font-medium ${filter === f.value ? 'border-primary bg-primary text-white' : 'border-border text-ink-muted hover:bg-surface-alt'}`}
            >
              {t(f.labelKey)}
            </button>
          ))}
        </div>

        <DataTable
          columns={columns}
          data={rowsQuery.data?.data ?? []}
          isLoading={rowsQuery.isLoading}
          error={rowsQuery.error}
          onRetry={() => rowsQuery.refetch()}
          emptyTitle={t('option.imports.rowsEmpty')}
        />
        {rowsQuery.data && <Pagination meta={rowsQuery.data.meta} onPageChange={setPage} />}
      </CardBody>

      <ConfirmDialog
        isOpen={confirmOpen}
        title={t('confirm.imports.importTitle')}
        description={`${tCount('confirm.imports.willImport', c.valid)} ${tCount('confirm.imports.willNotImport', c.review + c.duplicate + c.invalid)} ${t('confirm.imports.existingSkippedFinal')}`}
        confirmLabel={t('action.importYes')}
        isLoading={isRunning}
        onConfirm={() => {
          setConfirmOpen(false);
          void runImport();
        }}
        onCancel={() => setConfirmOpen(false)}
      />
      <ConfirmDialog
        isOpen={cancelOpen}
        title={t('confirm.imports.cancelTitle')}
        description={t('confirm.imports.cancelBody')}
        confirmLabel={t('action.cancelImport')}
        tone="danger"
        isLoading={cancelMutation.isPending}
        onConfirm={() => cancelMutation.mutate()}
        onCancel={() => setCancelOpen(false)}
      />
    </Card>
  );
}
