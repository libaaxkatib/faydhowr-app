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

const FILTERS: { value: ImportRowStatus | ''; label: string }[] = [
  { value: '', label: 'All rows' },
  { value: 'valid', label: 'Ready' },
  { value: 'review', label: 'Needs review' },
  { value: 'duplicate', label: 'Already exist' },
  { value: 'invalid', label: 'Invalid' },
  { value: 'imported', label: 'Imported' },
  { value: 'skipped', label: 'Skipped' },
  { value: 'failed', label: 'Failed' },
];

function errorText(error: unknown): string {
  if (error instanceof ApiClientError) return describeImportError(error.code, error.message);
  return error instanceof Error ? error.message : 'Something went wrong.';
}

/** The few sheet values worth showing in the preview (the rest stays in the sheet). */
function rowDetails(row: ImportRow): string {
  const d = row.details ?? {};
  const report = (row.meta?.report ?? null) as Record<string, string> | null;

  if (report) {
    const path = ACTIVATION_PATH_TEXT[report.proposed_path] ?? report.proposed_path;
    return [report.employee_number, report.current_status && `${report.current_status} → active`, path].filter(Boolean).join(' · ');
  }

  const name = (d.full_name ?? d.name) as string | undefined;
  const parts = [name, d.phone as string | undefined, d.category as string | undefined, d.gender as string | undefined].filter(Boolean);
  const candidates = (row.meta?.employee_numbers ?? null) as string[] | null;
  const suffix = candidates && candidates.length > 0 ? ` (candidates: ${candidates.join(', ')})` : '';
  return parts.length > 0 ? parts.join(' · ') + suffix : suffix.trim();
}

export function ExcelImportsPage() {
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
      if (!file) throw new Error('Choose a file first.');
      const problem = checkImportFile(file);
      if (problem) throw new Error(problem);
      return hrImportsApi.upload(type, file);
    },
    onSuccess: (batch) => {
      setFile(null);
      if (fileInput.current) fileInput.current.value = '';
      setSelectedId(batch.id);
      queryClient.invalidateQueries({ queryKey: ['hr-imports'] });
      show('File checked. Review the preview below; nothing has been imported yet.');
    },
    onError: (error) => {
      if (error instanceof ApiClientError && error.code === 'DUPLICATE_IMPORT') {
        const existing = (error.fieldErrors as unknown as { existing_batch_id?: number } | undefined)?.existing_batch_id;
        if (existing) setSelectedId(existing);
      }
      show(errorText(error), 'error');
    },
  });

  const historyColumns: ColumnDef<ImportBatch, unknown>[] = [
    { header: '#', accessorKey: 'id' },
    { header: 'Type', accessorKey: 'type_label' },
    {
      header: 'Status',
      cell: ({ row }) => <StatusBadge status={row.original.status} label={BATCH_STATUS[row.original.status].label} tone={BATCH_STATUS[row.original.status].tone} />,
    },
    {
      header: 'Rows',
      cell: ({ row }) => {
        const c = row.original.counts;
        return `${c.total} (${c.imported} imported)`;
      },
    },
    { header: 'Uploaded by', cell: ({ row }) => row.original.created_by ?? '—' },
    { header: 'When', cell: ({ row }) => formatDateTime(row.original.created_at) },
    {
      header: '',
      id: 'open',
      cell: ({ row }) => (
        <Button size="sm" variant="outline" onClick={() => setSelectedId(row.original.id)}>
          Open
        </Button>
      ),
    },
  ];

  return (
    <div>
      <PageHeader title="Excel Imports" breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Excel Imports' }]} />

      <Card className="mb-4">
        <CardHeader>
          <h3 className="font-display text-sm font-bold text-ink">1. Upload a workbook</h3>
        </CardHeader>
        <CardBody className="space-y-4">
          <p className="text-sm text-ink-muted">
            Only a Super Admin can import. Uploading only <strong>checks</strong> the file and shows a preview. Nothing is
            added or changed until you confirm, existing records are never updated, and the file itself is not kept.
          </p>

          <div className="grid gap-4 md:grid-cols-2">
            <FormField label="What are you importing?" htmlFor="import-type">
              <Select
                id="import-type"
                value={type}
                onChange={(e) => setType(e.target.value as ImportType)}
                options={IMPORT_TYPES.map((t) => ({ value: t.value, label: t.label }))}
              />
            </FormField>
            <FormField label="Excel (.xlsx) or CSV file" htmlFor="import-file" hint="Up to 5 MB and 2,000 rows.">
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
              <strong>Required columns (first row):</strong> {typeInfo.required.join(', ')}
            </p>
            {typeInfo.optional.length > 0 && (
              <p className="mt-1 text-xs text-ink-muted">
                <strong>Optional:</strong> {typeInfo.optional.join(', ')}
              </p>
            )}
            {type === 'employee_registrations' && (
              <p className="mt-1 text-xs text-ink-muted">
                An optional payment/account number is saved in its own field and is never shown in the preview. It is never used to match
                anyone: the registration phone is the only phone used for matching.
              </p>
            )}
          </div>

          <div className="flex items-center gap-3">
            <Button isLoading={uploadMutation.isPending} disabled={!file} onClick={() => uploadMutation.mutate()}>
              <Icon name="file-text" size={15} />
              Check file and show preview
            </Button>
            {file && <span className="text-xs text-ink-muted">{file.name}</span>}
          </div>
        </CardBody>
      </Card>

      {selectedId !== null && <BatchPanel key={selectedId} id={selectedId} onClose={() => setSelectedId(null)} />}

      <Card className="mt-4">
        <CardHeader>
          <h3 className="font-display text-sm font-bold text-ink">Import history</h3>
        </CardHeader>
        <CardBody className="p-0">
          <DataTable
            columns={historyColumns}
            data={history.data?.data ?? []}
            isLoading={history.isLoading}
            error={history.error}
            onRetry={() => history.refetch()}
            emptyTitle="No imports yet"
            emptyDescription="Uploaded workbooks and their results are listed here."
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
            show(`Import finished: ${batch.counts.imported} imported, ${batch.counts.skipped} skipped, ${batch.counts.failed} failed.`);
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
      show(errorText(error), 'error');
    } finally {
      running.current = false;
      setIsRunning(false);
      refreshAll();
    }
  }, [id, queryClient, refreshAll, show]);

  const cancelMutation = useMutation({
    mutationFn: () => hrImportsApi.cancel(id),
    onSuccess: () => {
      setCancelOpen(false);
      show('Import cancelled and its details deleted.');
      refreshAll();
    },
    onError: (error) => show(errorText(error), 'error'),
  });

  const reportMutation = useMutation({
    mutationFn: () => hrImportsApi.downloadReport(id, filter),
    onError: (error) => show(errorText(error), 'error'),
  });

  const batch = batchQuery.data;

  if (batchQuery.isLoading || !batch) {
    return (
      <Card className="mb-4">
        <CardBody>{batchQuery.error ? <p className="text-sm text-danger">{errorText(batchQuery.error)}</p> : <p className="text-sm text-ink-muted">Loading…</p>}</CardBody>
      </Card>
    );
  }

  const stage = batchStage(batch);
  const c = batch.counts;
  const days = daysUntilDeletion(batch.retention_expires_at);
  const progress = progressPercent(batch);

  const columns: ColumnDef<ImportRow, unknown>[] = [
    { header: 'Row', accessorKey: 'row_number' },
    {
      header: 'Result',
      cell: ({ row }) => <StatusBadge status={row.original.status} label={ROW_STATUS[row.original.status].label} tone={ROW_STATUS[row.original.status].tone} />,
    },
    { header: 'What it means', cell: ({ row }) => <span title={row.original.message ?? ''}>{rowCodeText(row.original.code) || row.original.message}</span> },
    { header: 'Details', cell: ({ row }) => <span className="text-xs text-ink-muted">{batch.details_deleted ? 'Deleted' : rowDetails(row.original)}</span> },
  ];

  return (
    <Card className="mb-4">
      <CardHeader className="flex-wrap">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="font-display text-base font-bold text-ink">
              Import #{batch.id} · {batch.type_label}
            </h3>
            <StatusBadge status={batch.status} label={BATCH_STATUS[batch.status].label} tone={BATCH_STATUS[batch.status].tone} />
          </div>
          <p className="mt-0.5 text-xs text-ink-muted">
            Uploaded {formatDateTime(batch.created_at)} by {batch.created_by ?? '—'}
            {batch.confirmed_at ? ` · confirmed ${formatDateTime(batch.confirmed_at)}` : ''}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button size="sm" variant="outline" isLoading={reportMutation.isPending} onClick={() => reportMutation.mutate()}>
            <Icon name="file-text" size={14} />
            Download report (CSV)
          </Button>
          {canCancel(batch) && (
            <Button size="sm" variant="outline" disabled={isRunning} onClick={() => setCancelOpen(true)}>
              Cancel import
            </Button>
          )}
          {canConfirm(batch) && (
            <Button size="sm" isLoading={isRunning} onClick={() => setConfirmOpen(true)}>
              <Icon name="check" size={14} />
              Import {c.valid} ready row{c.valid === 1 ? '' : 's'}
            </Button>
          )}
          <Button size="sm" variant="ghost" onClick={onClose}>
            Close
          </Button>
        </div>
      </CardHeader>

      <CardBody className="space-y-4">
        {stage === 'preview' && (
          <div className="rounded-sm border border-border bg-secondary-soft p-3 text-sm text-ink">
            <strong>Preview only.</strong> This checks the approved employee plan and shows how each person would become Active. It
            never activates anyone and cannot be confirmed. Activating real employees needs its own separate approval.
          </div>
        )}

        {batch.unrecognised_headers && batch.unrecognised_headers.length > 0 && (
          <p className="text-xs text-ink-muted">
            Columns that are not imported: {batch.unrecognised_headers.join(', ')}.
          </p>
        )}

        <div className="grid grid-cols-2 gap-2 md:grid-cols-5">
          <Tile label="Rows in the file" value={c.total} />
          <Tile label="Ready" value={c.valid} tone="success" />
          <Tile label="Needs review" value={c.review} tone="warning" />
          <Tile label="Already exist / repeated" value={c.duplicate} />
          <Tile label="Invalid" value={c.invalid} tone="danger" />
        </div>

        {(stage === 'importing' || stage === 'finished') && (
          <div>
            <div className="mb-1 flex justify-between text-xs text-ink-muted">
              <span>
                {c.imported} imported · {c.skipped} skipped · {c.failed} failed
              </span>
              <span>{progress}%</span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-surface-alt" role="progressbar" aria-valuenow={progress} aria-valuemin={0} aria-valuemax={100}>
              <div className="h-full bg-primary transition-all" style={{ width: `${progress}%` }} />
            </div>
            {stage === 'importing' && !isRunning && (
              <div className="mt-2 flex items-center gap-2 text-xs text-ink-muted">
                <span>The import stopped before it finished.</span>
                <Button size="sm" variant="outline" onClick={() => void runImport()}>
                  Continue importing
                </Button>
              </div>
            )}
          </div>
        )}

        {batch.activation_ready !== undefined && (
          <div className="grid gap-2 md:grid-cols-2">
            <div className="rounded-sm border border-border p-3 text-sm">
              <p className="font-medium text-success">Activation-ready: {batch.activation_ready}</p>
              <ul className="mt-1 space-y-0.5 text-ink-muted">
                {Object.entries(batch.activation_ready_by_path ?? {}).map(([path, count]) => (
                  <li key={path}>
                    {count} × {ACTIVATION_PATH_TEXT[path] ?? path}
                  </li>
                ))}
              </ul>
            </div>
            <div className="rounded-sm border border-border p-3 text-sm">
              <p className="font-medium text-warning">Genuine conflicts (excluded until corrected): {batch.activation_excluded_total ?? 0}</p>
              <p className="mt-1 text-xs text-ink-muted">
                An employee whose number or name does not match the plan, whose status changed, or whose pay data is missing. Nothing was
                matched, created or changed. Download the report for the reason on each row.
              </p>
              {(batch.activation_lookalikes_noted ?? 0) > 0 && (
                <p className="mt-2 text-xs text-ink-muted">
                  {batch.activation_lookalikes_noted} ready employee(s) have a look-alike record (same name or a different phone). They are
                  included because their employee number and name match the plan; nothing is merged, archived or changed.
                </p>
              )}
            </div>
          </div>
        )}

        {batch.activation_paths && (
          <div className="rounded-sm border border-border p-3 text-sm">
            <p className="mb-1 font-medium text-ink">How the employees would become Active (all rows checked)</p>
            <ul className="space-y-0.5 text-ink-muted">
              {Object.entries(batch.activation_paths).map(([path, count]) => (
                <li key={path}>
                  {count} × {ACTIVATION_PATH_TEXT[path] ?? path}
                </li>
              ))}
            </ul>
            {batch.activation_warnings && Object.keys(batch.activation_warnings).length > 0 && (
              <p className="mt-2 text-xs text-warning">
                Warnings: {Object.entries(batch.activation_warnings).map(([w, n]) => `${n} × ${w.replace(/_/g, ' ').toLowerCase()}`).join(', ')}
              </p>
            )}
          </div>
        )}

        <p className="text-xs text-ink-muted">
          {batch.details_deleted
            ? 'The personal details for this import have been deleted. Only the counts and result codes remain.'
            : days !== null
              ? `Personal details in this preview (names, phone numbers) are deleted automatically in ${days} day${days === 1 ? '' : 's'}.`
              : ''}
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
              {f.label}
            </button>
          ))}
        </div>

        <DataTable
          columns={columns}
          data={rowsQuery.data?.data ?? []}
          isLoading={rowsQuery.isLoading}
          error={rowsQuery.error}
          onRetry={() => rowsQuery.refetch()}
          emptyTitle="No rows in this view"
        />
        {rowsQuery.data && <Pagination meta={rowsQuery.data.meta} onPageChange={setPage} />}
      </CardBody>

      <ConfirmDialog
        isOpen={confirmOpen}
        title="Import the ready rows?"
        description={`${c.valid} ready row${c.valid === 1 ? '' : 's'} will be imported. ${c.review + c.duplicate + c.invalid} other row${c.review + c.duplicate + c.invalid === 1 ? '' : 's'} will not be imported. Records that already exist are skipped and never changed. This cannot be undone from this screen.`}
        confirmLabel="Yes, import"
        isLoading={isRunning}
        onConfirm={() => {
          setConfirmOpen(false);
          void runImport();
        }}
        onCancel={() => setConfirmOpen(false)}
      />
      <ConfirmDialog
        isOpen={cancelOpen}
        title="Cancel this import?"
        description="The preview and the personal details in it will be deleted now. Nothing has been imported. You can upload the file again later."
        confirmLabel="Cancel import"
        tone="danger"
        isLoading={cancelMutation.isPending}
        onConfirm={() => cancelMutation.mutate()}
        onCancel={() => setCancelOpen(false)}
      />
    </Card>
  );
}
