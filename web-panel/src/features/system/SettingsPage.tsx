import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { settingsApi } from '@/api/settings';
import { ApiClientError } from '@/api/client';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { useToast } from '@/components/ui/useToast';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { formatDateTime } from '@/utils/formatters';
import { SETTINGS_CATEGORY_LABELS, SETTINGS_CATEGORY_ORDER, SETTINGS_FIELD_SCHEMA } from '@/features/system/settingsSchema';
import type { SettingCategory, SettingFieldSchema } from '@/types/settings';

function qualifiedKey(category: SettingCategory, field: string): string {
  return `${category}.${field}`;
}

interface FieldInputProps {
  category: SettingCategory;
  field: SettingFieldSchema;
  value: unknown;
  onChange: (value: unknown) => void;
  disabled: boolean;
  error?: string;
}

function FieldInput({ category, field, value, onChange, disabled, error }: FieldInputProps) {
  const id = `setting-${category}-${field.key}`;

  if (field.type === 'boolean') {
    return (
      <div className="mb-4">
        <label className="flex items-center gap-2 text-sm text-ink" htmlFor={id}>
          <input
            id={id}
            type="checkbox"
            checked={Boolean(value)}
            disabled={disabled}
            onChange={(event) => onChange(event.target.checked)}
            className="h-4 w-4 rounded-sm border-border text-primary focus:ring-primary/30"
          />
          {field.label}
        </label>
        {error && <p className="mt-1 text-xs text-danger">{error}</p>}
      </div>
    );
  }

  if (field.type === 'multiselect') {
    const selected = new Set(Array.isArray(value) ? (value as string[]) : []);
    return (
      <FormField label={field.label} htmlFor={id} hint={field.hint} error={error}>
        <div className="flex flex-wrap gap-3 rounded-sm border border-border p-3">
          {field.options?.map((option) => (
            <label key={option.value} className="flex items-center gap-1.5 text-xs text-ink">
              <input
                type="checkbox"
                checked={selected.has(option.value)}
                disabled={disabled}
                onChange={(event) => {
                  const next = new Set(selected);
                  if (event.target.checked) next.add(option.value);
                  else next.delete(option.value);
                  onChange(Array.from(next));
                }}
                className="h-3.5 w-3.5 rounded-sm border-border text-primary focus:ring-primary/30"
              />
              {option.label}
            </label>
          ))}
        </div>
      </FormField>
    );
  }

  if (field.type === 'select') {
    return (
      <FormField label={field.label} htmlFor={id} hint={field.hint} error={error}>
        <Select
          id={id}
          value={value == null ? '' : String(value)}
          disabled={disabled}
          onChange={(event) => onChange(event.target.value)}
          options={field.options ?? []}
        />
      </FormField>
    );
  }

  const inputType = field.type === 'password' ? 'password' : field.type === 'number' ? 'number' : field.type === 'time' ? 'time' : 'text';

  return (
    <FormField label={field.label} htmlFor={id} hint={field.hint} error={error}>
      <input
        id={id}
        type={inputType}
        disabled={disabled}
        className={inputClasses}
        value={value == null ? '' : String(value)}
        placeholder={field.type === 'password' ? '••••••••' : undefined}
        onChange={(event) => onChange(field.type === 'number' ? (event.target.value === '' ? '' : Number(event.target.value)) : event.target.value)}
      />
    </FormField>
  );
}

function CategoryForm({ category }: { category: SettingCategory }) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { hasPermission } = useEffectivePermissions();
  const canManage = hasPermission('settings.manage');
  const fields = SETTINGS_FIELD_SCHEMA[category] ?? [];

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['settings', category],
    queryFn: () => settingsApi.get(category),
  });

  const canView = hasPermission('settings.view');
  const recentChanges = useQuery({
    queryKey: ['settings-audit-log', category],
    queryFn: () => settingsApi.auditLogs({ category, limit: 5 }),
    enabled: canView,
  });

  const [draft, setDraft] = useState<Record<string, unknown>>({});
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [isRestoreConfirmOpen, setIsRestoreConfirmOpen] = useState(false);
  const [testEmail, setTestEmail] = useState('');

  useEffect(() => {
    if (data) {
      const next: Record<string, unknown> = {};
      for (const field of fields) {
        // SMTP password is never returned as a real value (only a mask, or null) —
        // leave it blank so an unchanged field is never accidentally resubmitted.
        next[field.key] = field.type === 'password' ? '' : data.settings[qualifiedKey(category, field.key)];
      }
      setDraft(next);
      setFieldErrors({});
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data]);

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload: Record<string, unknown> = {};
      for (const field of fields) {
        if (field.type === 'password' && draft[field.key] === '') continue; // omit — "sometimes" rule leaves it untouched
        payload[qualifiedKey(category, field.key)] = draft[field.key];
      }
      return settingsApi.update(category, payload);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['settings', category] });
      queryClient.invalidateQueries({ queryKey: ['settings-audit-log', category] });
      show(`${SETTINGS_CATEGORY_LABELS[category]} settings saved.`);
    },
    onError: (err) => {
      if (err instanceof ApiClientError && err.isValidation) {
        setFieldErrors(err.fieldErrors ?? {});
      } else {
        show(err instanceof ApiClientError ? err.message : 'Could not save settings.', 'error');
      }
    },
  });

  const restoreMutation = useMutation({
    mutationFn: () => settingsApi.restoreDefaults(category),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['settings', category] });
      show(`${SETTINGS_CATEGORY_LABELS[category]} settings restored to defaults.`);
      setIsRestoreConfirmOpen(false);
    },
    onError: (err) => {
      show(err instanceof ApiClientError ? err.message : 'Could not restore defaults.', 'error');
      setIsRestoreConfirmOpen(false);
    },
  });

  const smtpTestMutation = useMutation({
    mutationFn: () => settingsApi.sendSmtpTest(testEmail),
    onSuccess: () => show('Test email sent.'),
    onError: (err) => show(err instanceof ApiClientError ? err.message : 'Could not send test email.', 'error'),
  });

  function handleSubmit(event: FormEvent) {
    event.preventDefault();
    saveMutation.mutate();
  }

  return (
    <Card className="p-5">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 className="font-display text-base font-bold text-ink">{SETTINGS_CATEGORY_LABELS[category]} settings</h3>
          {data?.last_updated_by && (
            <p className="text-xs text-ink-faint">
              Last updated by {data.last_updated_by.name} ({data.last_updated_by.role.replace('_', ' ')})
              {data.updated_at && ` · ${formatDateTime(data.updated_at)}`}
            </p>
          )}
        </div>
        {canManage && (
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={() => setIsRestoreConfirmOpen(true)} disabled={saveMutation.isPending}>
              Restore defaults
            </Button>
            <Button size="sm" onClick={handleSubmit} isLoading={saveMutation.isPending}>
              Save changes
            </Button>
          </div>
        )}
      </div>

      {isLoading && <LoadingState label="Loading settings…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {data && (
        <form onSubmit={handleSubmit}>
          <div className="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
            {fields.map((field) => (
              <FieldInput
                key={field.key}
                category={category}
                field={field}
                value={draft[field.key]}
                disabled={!canManage || saveMutation.isPending}
                error={fieldErrors[qualifiedKey(category, field.key)]?.[0]}
                onChange={(value) => setDraft((prev) => ({ ...prev, [field.key]: value }))}
              />
            ))}
          </div>

          {category === 'smtp' && (
            <div className="mt-2 border-t border-border pt-4">
              <p className="mb-2 text-xs font-bold uppercase tracking-wide text-ink-faint">Send test email</p>
              <div className="flex items-center gap-2">
                <input
                  type="email"
                  placeholder="you@example.com"
                  className={inputClasses + ' max-w-xs'}
                  value={testEmail}
                  disabled={!canManage}
                  onChange={(event) => setTestEmail(event.target.value)}
                />
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={!canManage || !testEmail}
                  isLoading={smtpTestMutation.isPending}
                  onClick={() => smtpTestMutation.mutate()}
                >
                  Send test
                </Button>
              </div>
            </div>
          )}

          {category === 'backup' && <BackupsPanel canManage={canManage} />}
        </form>
      )}

      {canView && recentChanges.data && recentChanges.data.length > 0 && (
        <div className="mt-4 border-t border-border pt-4">
          <p className="mb-2 text-xs font-bold uppercase tracking-wide text-ink-faint">Recent changes</p>
          <div className="space-y-1.5 text-xs text-ink-muted">
            {recentChanges.data.map((entry) => (
              <p key={entry.id}>
                <span className="font-medium text-ink">{entry.key}</span> changed by{' '}
                {entry.changed_by?.name ?? 'unknown'} · {formatDateTime(entry.changed_at)}
              </p>
            ))}
          </div>
        </div>
      )}

      <ConfirmDialog
        isOpen={isRestoreConfirmOpen}
        title={`Restore ${SETTINGS_CATEGORY_LABELS[category]} defaults?`}
        description="This replaces every value in this category with its factory default. This can be undone by editing the values again, but not automatically."
        confirmLabel="Restore defaults"
        tone="danger"
        isLoading={restoreMutation.isPending}
        onConfirm={() => restoreMutation.mutate()}
        onCancel={() => setIsRestoreConfirmOpen(false)}
      />
    </Card>
  );
}

function BackupsPanel({ canManage }: { canManage: boolean }) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [restoreTarget, setRestoreTarget] = useState<string | null>(null);
  const [restoreConfirmText, setRestoreConfirmText] = useState('');

  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['backups'], queryFn: settingsApi.backups.list });

  const createMutation = useMutation({
    mutationFn: settingsApi.backups.create,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['backups'] });
      show('Backup created.');
    },
    onError: (err) => show(err instanceof ApiClientError ? err.message : 'Could not create backup.', 'error'),
  });

  const restoreMutation = useMutation({
    mutationFn: (id: string) => settingsApi.backups.restore(id),
    onSuccess: () => {
      show('Backup restored.');
      setRestoreTarget(null);
      setRestoreConfirmText('');
    },
    onError: (err) => show(err instanceof ApiClientError ? err.message : 'Could not restore backup.', 'error'),
  });

  return (
    <div className="mt-2 border-t border-border pt-4">
      <div className="mb-3 flex items-center justify-between">
        <p className="text-xs font-bold uppercase tracking-wide text-ink-faint">Backups</p>
        {canManage && (
          <Button type="button" variant="outline" size="sm" isLoading={createMutation.isPending} onClick={() => createMutation.mutate()}>
            <Icon name="plus" size={13} /> Create backup
          </Button>
        )}
      </div>

      {isLoading && <LoadingState label="Loading backups…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}
      {data && data.length === 0 && <EmptyState icon="box" title="No backups yet" />}

      {data && data.length > 0 && (
        <div className="divide-y divide-border rounded-md border border-border">
          {data.map((backup) => (
            <div key={backup.id} className="flex items-center justify-between px-3.5 py-2.5 text-sm">
              <div>
                <p className="font-medium text-ink">{backup.id}</p>
                <p className="text-xs text-ink-faint">
                  {(backup.size_bytes / 1024).toFixed(1)} KB · {backup.created_by ?? 'system'} · {formatDateTime(backup.created_at)}
                </p>
              </div>
              {canManage && (
                <div className="flex items-center gap-1">
                  <button
                    type="button"
                    title="Download"
                    onClick={() => settingsApi.backups.download(backup.id, `${backup.id}.zip`)}
                    className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt hover:text-primary"
                  >
                    <Icon name="arrow-left" size={14} className="-rotate-90" />
                  </button>
                  <button
                    type="button"
                    title="Restore this backup"
                    onClick={() => setRestoreTarget(backup.id)}
                    className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-danger-soft hover:text-danger"
                  >
                    <Icon name="refresh" size={14} />
                  </button>
                </div>
              )}
            </div>
          ))}
        </div>
      )}

      <Modal
        isOpen={restoreTarget !== null}
        onClose={() => {
          setRestoreTarget(null);
          setRestoreConfirmText('');
        }}
        title="Restore backup — destructive"
        size="sm"
        footer={
          <>
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setRestoreTarget(null);
                setRestoreConfirmText('');
              }}
              disabled={restoreMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="danger"
              size="sm"
              disabled={restoreConfirmText !== 'RESTORE'}
              isLoading={restoreMutation.isPending}
              onClick={() => restoreTarget && restoreMutation.mutate(restoreTarget)}
            >
              Restore
            </Button>
          </>
        }
      >
        <p className="mb-3 text-sm text-ink-muted">
          This overwrites current settings with the contents of backup <strong>{restoreTarget}</strong>. This cannot be undone except by
          restoring a different backup. Type <strong>RESTORE</strong> to confirm.
        </p>
        <input
          className={inputClasses}
          value={restoreConfirmText}
          onChange={(event) => setRestoreConfirmText(event.target.value)}
          placeholder="RESTORE"
        />
      </Modal>
    </div>
  );
}

function BranchesPanel() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { hasPermission } = useEffectivePermissions();
  const canManage = hasPermission('settings.manage');

  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['branches'], queryFn: settingsApi.branches.list });

  const activateMutation = useMutation({
    mutationFn: settingsApi.branches.activate,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['branches'] });
      show('Branch activated.');
    },
    onError: (err) => show(err instanceof ApiClientError ? err.message : 'Could not activate branch.', 'error'),
  });

  const defaultMutation = useMutation({
    mutationFn: settingsApi.branches.makeDefault,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['branches'] });
      show('Default branch updated.');
    },
    onError: (err) => show(err instanceof ApiClientError ? err.message : 'Could not set default branch.', 'error'),
  });

  return (
    <Card className="p-5">
      <h3 className="mb-4 font-display text-base font-bold text-ink">Branches</h3>

      {isLoading && <LoadingState label="Loading branches…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}
      {data && data.length === 0 && <EmptyState icon="briefcase" title="No branches yet" />}

      {data && data.length > 0 && (
        <div className="divide-y divide-border rounded-md border border-border">
          {data.map((branch) => (
            <div key={branch.id} className="flex items-center justify-between px-3.5 py-3 text-sm">
              <div>
                <p className="font-medium text-ink">
                  {branch.name} <span className="text-ink-faint">({branch.code})</span>
                  {branch.is_default && (
                    <span className="ml-2 rounded-full bg-primary-soft px-2 py-0.5 text-[10px] font-bold text-primary">DEFAULT</span>
                  )}
                </p>
                <p className="text-xs text-ink-faint">{branch.city ?? '—'}</p>
              </div>
              <div className="flex items-center gap-2">
                <StatusBadge status={branch.status} />
                {canManage && branch.status !== 'active' && (
                  <Button size="sm" variant="outline" isLoading={activateMutation.isPending} onClick={() => activateMutation.mutate(branch.id)}>
                    Activate
                  </Button>
                )}
                {canManage && branch.status === 'active' && !branch.is_default && (
                  <Button size="sm" variant="outline" isLoading={defaultMutation.isPending} onClick={() => defaultMutation.mutate(branch.id)}>
                    Set default
                  </Button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </Card>
  );
}

export function SettingsPage() {
  const [selected, setSelected] = useState<SettingCategory>('company');

  return (
    <div>
      <PageHeader title="Settings" breadcrumb={[{ label: 'System', to: '/system/settings' }, { label: 'Settings' }]} />

      <div className="grid grid-cols-1 gap-5 md:grid-cols-[200px_1fr]">
        <div className="space-y-1">
          {SETTINGS_CATEGORY_ORDER.map((category) => (
            <button
              key={category}
              type="button"
              onClick={() => setSelected(category)}
              className={`w-full rounded-sm px-3 py-2 text-left text-sm font-medium transition ${
                selected === category ? 'bg-primary-soft text-primary' : 'text-ink-muted hover:bg-surface-alt'
              }`}
            >
              {SETTINGS_CATEGORY_LABELS[category]}
            </button>
          ))}
        </div>

        {selected === 'branch' ? <BranchesPanel /> : <CategoryForm category={selected} />}
      </div>
    </div>
  );
}
