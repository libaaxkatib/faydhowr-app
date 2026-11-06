import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
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
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { useToast } from '@/components/ui/useToast';
import { useAuth } from '@/features/auth/useAuth';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { useI18n } from '@/i18n/useI18n';
import { invalidateMarketing } from '@/utils/marketingCache';
import { canManageMarketingTeams } from '@/utils/marketingAccess';
import { marketingErrorMessage } from '@/utils/marketingErrors';
import { validateTeamName } from '@/utils/marketingFormRules';

export function TeamsPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t } = useI18n();
  const { admin, isSuperAdmin } = useAuth();
  const { hasPermission } = useEffectivePermissions();
  // Creating a team is for the Super Admin and the Marketing Manager (server: requireUnrestricted); a team member also holds marketing.manage but may not.
  // Deleting a team and changing its members follow the server's own answer for each team (can_manage_members), exactly like the Employees page.
  const canCreateTeams = canManageMarketingTeams({ isSuperAdmin, role: admin?.role, hasPermission });
  const [isOpen, setIsOpen] = useState(false);
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [nameError, setNameError] = useState<string | undefined>(undefined);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [pendingMemberByTeam, setPendingMemberByTeam] = useState<Record<number, string>>({});
  const [pendingDeleteTeam, setPendingDeleteTeam] = useState<{ id: number; name: string } | null>(null);
  const [pendingRemoval, setPendingRemoval] = useState<{ teamId: number; adminId: number; name: string } | null>(null);

  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['marketing-teams'], queryFn: marketingApi.teams.list });
  const { data: employees } = useQuery({ queryKey: ['marketing-employees'], queryFn: marketingApi.employees.list });

  const createMutation = useMutation({
    mutationFn: () => marketingApi.teams.create({ name: name.trim(), description: description || null }),
    onSuccess: () => {
      invalidateMarketing(queryClient);
      show(t('notify.marketing.teamCreated'));
      setIsOpen(false);
      setName('');
      setDescription('');
    },
    onError: (err) => {
      if (err instanceof ApiClientError && err.isValidation) {
        setServerErrors(err.fieldErrors ?? {});
      } else {
        show(marketingErrorMessage(err, t('notify.marketing.somethingWentWrong')), 'error');
      }
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => marketingApi.teams.remove(id),
    onSuccess: () => {
      invalidateMarketing(queryClient);
      show(t('notify.marketing.teamDeleted'));
    },
    onError: (err) => show(marketingErrorMessage(err, t('notify.marketing.couldNotDeleteTeam')), 'error'),
    onSettled: () => setPendingDeleteTeam(null),
  });

  const addMemberMutation = useMutation({
    mutationFn: ({ teamId, adminId }: { teamId: number; adminId: number }) => marketingApi.teams.addMember(teamId, adminId),
    onSuccess: (_data, variables) => {
      invalidateMarketing(queryClient);
      setPendingMemberByTeam((prev) => ({ ...prev, [variables.teamId]: '' }));
      show(t('notify.marketing.memberAdded'));
    },
    onError: (err) => show(marketingErrorMessage(err, t('notify.marketing.couldNotAddMember')), 'error'),
  });

  const removeMemberMutation = useMutation({
    mutationFn: ({ teamId, adminId }: { teamId: number; adminId: number }) => marketingApi.teams.removeMember(teamId, adminId),
    onSuccess: () => {
      invalidateMarketing(queryClient);
      show(t('notify.marketing.memberRemoved'));
    },
    onError: (err) => show(marketingErrorMessage(err, t('notify.marketing.couldNotRemoveMember')), 'error'),
    onSettled: () => setPendingRemoval(null),
  });

  function openCreate() {
    setNameError(undefined);
    setServerErrors({});
    setIsOpen(true);
  }

  function submitCreate() {
    const problem = validateTeamName(name);
    setNameError(problem);
    setServerErrors({});
    if (!problem) createMutation.mutate();
  }

  return (
    <div>
      <PageHeader
        title={t('nav.marketing.teams')}
        breadcrumb={[{ label: t('nav.marketing.marketing'), to: '/marketing' }, { label: t('nav.marketing.teams') }]}
        actions={
          canCreateTeams ? (
            <Button onClick={openCreate}>
              <Icon name="plus" size={15} />
              {t('action.marketing.addTeam')}
            </Button>
          ) : undefined
        }
      />

      {isLoading && <LoadingState label={t('option.marketing.loadingTeams')} />}
      {error && <ErrorState error={error} onRetry={refetch} />}
      {data && data.length === 0 && <EmptyState icon="users" title={t('warning.marketing.noTeams')} />}

      {data && data.length > 0 && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          {data.map((team) => (
            <Card key={team.id} className="p-5">
              <div className="mb-3 flex items-center justify-between">
                <h3 className="font-display text-base font-bold text-ink">{team.name}</h3>
                {team.can_manage_members === true && (
                  <button
                    type="button"
                    aria-label={t('action.marketing.deleteTeamNamed', { name: team.name })}
                    title={t('action.marketing.deleteTeamNamed', { name: team.name })}
                    onClick={() => setPendingDeleteTeam({ id: team.id, name: team.name })}
                    className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-danger-soft hover:text-danger"
                  >
                    <Icon name="trash" size={14} />
                  </button>
                )}
              </div>
              {team.description && <p className="mb-3 text-sm text-ink-muted">{team.description}</p>}
              <div>
                <p className="mb-1.5 text-xs font-medium text-ink-faint">{t('option.marketing.members')}</p>
                {team.members && team.members.length > 0 ? (
                  <div className="flex flex-wrap gap-1.5">
                    {team.members.map((m) => (
                      <span key={m.id} className="flex items-center gap-1 rounded-full bg-primary-soft py-1 pl-2.5 pr-1 text-xs font-medium text-primary">
                        {m.full_name}
                        {team.can_manage_members === true && (
                          <button
                            type="button"
                            aria-label={t('action.marketing.removeMemberNamed', { name: m.full_name })}
                            title={t('action.marketing.removeMemberNamed', { name: m.full_name })}
                            onClick={() => setPendingRemoval({ teamId: team.id, adminId: m.id, name: m.full_name })}
                            className="flex h-4 w-4 items-center justify-center rounded-full hover:bg-primary/20"
                          >
                            <Icon name="x" size={10} />
                          </button>
                        )}
                      </span>
                    ))}
                  </div>
                ) : (
                  <p className="text-xs text-ink-faint">{t('warning.marketing.noMembers')}</p>
                )}
              </div>
              {team.can_manage_members === true && (
                <div className="mt-3 flex items-center gap-1.5 border-t border-border pt-3">
                  <Select
                    value={pendingMemberByTeam[team.id] ?? ''}
                    onChange={(e) => setPendingMemberByTeam((prev) => ({ ...prev, [team.id]: e.target.value }))}
                    placeholder={t('option.marketing.addEmployeePlaceholder')}
                    options={(employees ?? [])
                      .filter((e) => !team.members?.some((m) => m.id === e.id))
                      .map((e) => ({ value: String(e.id), label: e.full_name }))}
                    className="h-8 flex-1 text-xs"
                  />
                  <Button
                    size="sm"
                    className="h-8 px-2 text-xs"
                    disabled={!pendingMemberByTeam[team.id]}
                    isLoading={addMemberMutation.isPending}
                    onClick={() => addMemberMutation.mutate({ teamId: team.id, adminId: Number(pendingMemberByTeam[team.id]) })}
                  >
                    {t('action.add')}
                  </Button>
                </div>
              )}
            </Card>
          ))}
        </div>
      )}

      <Modal
        isOpen={isOpen}
        onClose={() => setIsOpen(false)}
        title={t('action.marketing.addTeam')}
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setIsOpen(false)} disabled={createMutation.isPending}>{t('action.cancel')}</Button>
            <Button size="sm" isLoading={createMutation.isPending} onClick={submitCreate}>{t('action.create')}</Button>
          </>
        }
      >
        <FormField label={t('option.marketing.teamName')} htmlFor="team-name" required error={nameError ?? serverErrors.name?.[0]}>
          <input id="team-name" className={inputClasses} value={name} onChange={(e) => setName(e.target.value)} />
        </FormField>
        <FormField label={t('option.label.description')} htmlFor="team-description" error={serverErrors.description?.[0]}>
          <textarea id="team-description" rows={2} className={inputClasses + ' h-auto py-2'} value={description} onChange={(e) => setDescription(e.target.value)} />
        </FormField>
      </Modal>

      <ConfirmDialog
        isOpen={pendingDeleteTeam !== null}
        title={t('confirm.marketing.deleteTeamTitle')}
        description={t('confirm.marketing.deleteTeamBody', { name: pendingDeleteTeam?.name ?? '' })}
        confirmLabel={t('confirm.marketing.deleteTeamButton')}
        tone="danger"
        isLoading={deleteMutation.isPending}
        onConfirm={() => pendingDeleteTeam && deleteMutation.mutate(pendingDeleteTeam.id)}
        onCancel={() => setPendingDeleteTeam(null)}
      />

      <ConfirmDialog
        isOpen={pendingRemoval !== null}
        title={t('confirm.marketing.removeMemberTitle')}
        description={t('confirm.marketing.removeMemberBody', { name: pendingRemoval?.name ?? '' })}
        confirmLabel={t('confirm.marketing.removeMemberButton')}
        tone="danger"
        isLoading={removeMemberMutation.isPending}
        onConfirm={() => pendingRemoval && removeMemberMutation.mutate({ teamId: pendingRemoval.teamId, adminId: pendingRemoval.adminId })}
        onCancel={() => setPendingRemoval(null)}
      />
    </div>
  );
}
