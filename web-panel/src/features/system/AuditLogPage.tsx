import { useMemo, useState } from 'react';
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
import { ADMIN_ROLE_LABELS } from '@/types/system';
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

export function AuditLogPage() {
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
        header: 'When',
        accessorKey: 'created_at',
        cell: ({ row }) => <span className="text-ink-muted">{formatDateTime(row.original.created_at)}</span>,
      },
      {
        header: 'Admin',
        accessorKey: 'admin',
        cell: ({ row }) =>
          row.original.admin ? (
            <div>
              <p className="text-ink">{row.original.admin.full_name}</p>
              <p className="text-xs text-ink-faint">{ADMIN_ROLE_LABELS[row.original.admin.role] ?? row.original.admin.role}</p>
            </div>
          ) : (
            <span className="text-ink-faint">System</span>
          ),
      },
      {
        header: 'Action',
        accessorKey: 'action',
        cell: ({ row }) => <span className="text-ink">{row.original.action.replace(/_/g, ' ')}</span>,
      },
      {
        header: 'Entity',
        accessorKey: 'entity_type',
        cell: ({ row }) => (
          <span className="text-ink-muted">
            {row.original.entity_type ? row.original.entity_type.split('\\').pop() : '—'}
            {row.original.entity_id != null && ` #${row.original.entity_id}`}
          </span>
        ),
      },
      {
        header: 'Description',
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
              Details
            </button>
          ),
      },
    ],
    [],
  );

  return (
    <div>
      <PageHeader title="Audit Log" breadcrumb={[{ label: 'System', to: '/system/audit-log' }, { label: 'Audit Log' }]} />

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
          <Select
            value={action}
            onChange={(event) => {
              setAction(event.target.value);
              setPage(1);
            }}
            options={ACTION_OPTIONS}
            placeholder="All actions"
            className="w-44"
          />
          <Select
            value={adminId}
            onChange={(event) => {
              setAdminId(event.target.value);
              setPage(1);
            }}
            options={(admins.data?.data ?? []).map((a) => ({ value: String(a.id), label: a.full_name }))}
            placeholder="All admins"
            className="w-44"
          />
          <input
            type="text"
            value={entityType}
            onChange={(event) => {
              setEntityType(event.target.value);
              setPage(1);
            }}
            placeholder="Entity type (e.g. Admin)"
            className="h-10 w-44 rounded-sm border border-border bg-surface px-3 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
          />
          <input
            type="date"
            value={dateFrom}
            onChange={(event) => {
              setDateFrom(event.target.value);
              setPage(1);
            }}
            className="h-10 rounded-sm border border-border bg-surface px-3 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
          />
          <input
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
          emptyTitle="No audit log entries found"
          emptyDescription="Try adjusting your filters."
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>

      <Modal isOpen={detail !== null} onClose={() => setDetail(null)} title="Audit log entry" size="md">
        {detail && (
          <div className="space-y-3 text-sm">
            <p>
              <span className="font-medium text-ink">When:</span> <span className="text-ink-muted">{formatDateTime(detail.created_at)}</span>
            </p>
            <p>
              <span className="font-medium text-ink">Admin:</span>{' '}
              <span className="text-ink-muted">{detail.admin?.full_name ?? 'System'}</span>
            </p>
            <p>
              <span className="font-medium text-ink">Action:</span>{' '}
              <span className="text-ink-muted">{detail.action.replace(/_/g, ' ')}</span>
            </p>
            <p>
              <span className="font-medium text-ink">Description:</span> <span className="text-ink-muted">{detail.description}</span>
            </p>
            {detail.metadata && (
              <div>
                <p className="mb-1 font-medium text-ink">Metadata</p>
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
