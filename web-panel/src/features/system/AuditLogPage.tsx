import { useCallback, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';

import { auditLogsApi } from '@/api/auditLogs';
import { adminsApi } from '@/api/admins';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Select } from '@/components/ui/Select';
import { DataTable } from '@/components/ui/DataTable';
import { Pagination } from '@/components/ui/Pagination';
import { Modal } from '@/components/ui/Modal';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { formatDateTime } from '@/utils/formatters';
import { adminRoleLabel } from '@/types/system';
import { useI18n } from '@/i18n/useI18n';
import type { AuditLogEntry } from '@/types/auditLog';

/** Every AuditAction value the backend defines (App\Enums\AuditAction) — not fabricated, copied from source. */
const ACTION_OPTIONS = [
  'login', 'logout', 'create', 'update', 'delete', 'approve', 'cancel', 'payment',
  'permission_update', 'role_update', 'password_change', 'password_reset',
  'payment_confirm', 'payment_reject',
  'booking_schedule', 'booking_start', 'booking_complete', 'booking_close', 'booking_cancel',
  'store_order_status_change',
  'quotation_assign', 'quotation_issue', 'quotation_revision', 'quotation_discussion_reply',
  'quotation_close_discussion', 'quotation_expire', 'quotation_cancel', 'quotation_admin_accept',
  'hero_banner_create', 'hero_banner_update', 'hero_banner_delete', 'hero_banner_publish', 'hero_banner_hide',
  'gallery_create', 'gallery_update', 'gallery_delete',
  'faq_create', 'faq_update', 'faq_delete',
  'service_feature_toggle',
].map((value) => ({ value, label: value.replace(/_/g, ' ') }));

/** An audit action as the screen shows it in English: the stored value with spaces for underscores. */
function englishAction(value: string): string {
  return value.replace(/_/g, ' ');
}

export function AuditLogPage() {
  const { t, tValue } = useI18n();
  const actionLabel = useCallback((value: string): string => tValue('option.system.auditAction', value) ?? englishAction(value), [tValue]);
  const [action, setAction] = useState('');
  const [entityType, setEntityType] = useState('');
  const [adminId, setAdminId] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [page, setPage] = useState(1);
  const [detail, setDetail] = useState<AuditLogEntry | null>(null);
  const debouncedEntityType = useDebouncedValue(entityType);

  const admins = useQuery({ queryKey: ['admins-for-audit-filter'], queryFn: () => adminsApi.list({ per_page: 100 }) });

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['audit-logs', { action, entityType: debouncedEntityType, adminId, dateFrom, dateTo, page }],
    queryFn: () =>
      auditLogsApi.list({
        action: action || undefined,
        entity_type: debouncedEntityType || undefined,
        admin_id: adminId ? Number(adminId) : undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        page,
        per_page: 20,
      }),
    placeholderData: (previous) => previous,
  });

  const columns = useMemo<ColumnDef<AuditLogEntry, unknown>[]>(
    () => [
      {
        header: t('option.system.colWhen'),
        accessorKey: 'created_at',
        cell: ({ row }) => <span className="text-ink-muted">{formatDateTime(row.original.created_at)}</span>,
      },
      {
        header: t('option.system.colAdmin'),
        accessorKey: 'admin',
        cell: ({ row }) =>
          row.original.admin ? (
            <div>
              <p className="text-ink">{row.original.admin.full_name}</p>
              <p className="text-xs text-ink-faint">{adminRoleLabel(row.original.admin.role, tValue)}</p>
            </div>
          ) : (
            <span className="text-ink-faint">{t('option.system.system')}</span>
          ),
      },
      {
        header: t('option.system.colAction'),
        accessorKey: 'action',
        cell: ({ row }) => <span className="text-ink">{actionLabel(row.original.action)}</span>,
      },
      {
        header: t('option.system.colEntity'),
        accessorKey: 'entity_type',
        cell: ({ row }) => (
          <span className="text-ink-muted">
            {row.original.entity_type ? row.original.entity_type.split('\\').pop() : '—'}
            {row.original.entity_id != null && ` #${row.original.entity_id}`}
          </span>
        ),
      },
      {
        header: t('option.system.colDescription'),
        accessorKey: 'description',
        cell: ({ row }) => <span className="text-ink">{row.original.description}</span>,
      },
      {
        header: '',
        id: 'actions',
        cell: ({ row }) =>
          row.original.metadata && (
            <button
              type="button"
              onClick={(event) => {
                event.stopPropagation();
                setDetail(row.original);
              }}
              className="text-xs font-medium text-primary hover:underline"
            >
              {t('action.system.details')}
            </button>
          ),
      },
    ],
    [t, tValue, actionLabel],
  );

  const actionOptions = ACTION_OPTIONS.map((option) => ({ value: option.value, label: actionLabel(option.value) }));

  return (
    <div>
      <PageHeader
        title={t('option.system.pageAuditLog')}
        breadcrumb={[{ label: t('option.system.system'), to: '/system/audit-log' }, { label: t('option.system.pageAuditLog') }]}
      />

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
          <Select
            value={action}
            onChange={(event) => {
              setAction(event.target.value);
              setPage(1);
            }}
            options={actionOptions}
            placeholder={t('option.system.allActions')}
            className="w-44"
          />
          <Select
            value={adminId}
            onChange={(event) => {
              setAdminId(event.target.value);
              setPage(1);
            }}
            options={(admins.data?.data ?? []).map((a) => ({ value: String(a.id), label: a.full_name }))}
            placeholder={t('option.system.allAdmins')}
            className="w-44"
          />
          <input
            type="text"
            value={entityType}
            onChange={(event) => {
              setEntityType(event.target.value);
              setPage(1);
            }}
            placeholder={t('option.system.entityTypePlaceholder')}
            className="h-10 w-44 rounded-sm border border-border bg-surface px-3 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
          />
          <input
            aria-label={t('option.reports.dateFrom')}
            type="date"
            value={dateFrom}
            onChange={(event) => {
              setDateFrom(event.target.value);
              setPage(1);
            }}
            className="h-10 rounded-sm border border-border bg-surface px-3 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
          />
          <input
            aria-label={t('option.reports.dateTo')}
            type="date"
            value={dateTo}
            onChange={(event) => {
              setDateTo(event.target.value);
              setPage(1);
            }}
            className="h-10 rounded-sm border border-border bg-surface px-3 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
          />
        </div>

        <DataTable
          columns={columns}
          data={data?.data ?? []}
          isLoading={isLoading}
          error={error}
          onRetry={refetch}
          emptyTitle={t('warning.system.noAuditEntries')}
          emptyDescription={t('option.system.noMatchFiltersHint')}
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>

      <Modal isOpen={detail !== null} onClose={() => setDetail(null)} title={t('option.system.auditEntryTitle')} size="md">
        {detail && (
          <div className="space-y-3 text-sm">
            <p>
              <span className="font-medium text-ink">{t('option.system.colWhen')}:</span> <span className="text-ink-muted">{formatDateTime(detail.created_at)}</span>
            </p>
            <p>
              <span className="font-medium text-ink">{t('option.system.colAdmin')}:</span>{' '}
              <span className="text-ink-muted">{detail.admin?.full_name ?? t('option.system.system')}</span>
            </p>
            <p>
              <span className="font-medium text-ink">{t('option.system.colAction')}:</span>{' '}
              <span className="text-ink-muted">{actionLabel(detail.action)}</span>
            </p>
            <p>
              <span className="font-medium text-ink">{t('option.system.colDescription')}:</span> <span className="text-ink-muted">{detail.description}</span>
            </p>
            {detail.metadata && (
              <div>
                <p className="mb-1 font-medium text-ink">{t('option.system.metadata')}</p>
                <pre className="max-h-64 overflow-auto rounded-sm bg-surface-alt p-3 text-xs text-ink-muted">
                  {JSON.stringify(detail.metadata, null, 2)}
                </pre>
              </div>
            )}
          </div>
        )}
      </Modal>
    </div>
  );
}
