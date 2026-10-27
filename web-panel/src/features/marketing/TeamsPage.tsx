import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
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
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { useI18n } from '@/i18n/useI18n';

export function TeamsPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t } = useI18n();
  const [isOpen, setIsOpen] = useState(false);
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [pendingMemberByTeam, setPendingMemberByTeam] = useState<Record<number, string>>({});

  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['marketing-teams'], queryFn: marketingApi.teams.list });
  const { data: employees } = useQuery({ queryKey: ['marketing-employees'], queryFn: marketingApi.employees.list });

  const createMutation = useMutation({
    mutationFn: () => marketingApi.teams.create({ name, description: description || null }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['marketing-teams'] });
      show(t('notify.marketing.teamCreated'));
      setIsOpen(false);
      setName('');
      setDescription('');
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.marketing.somethingWentWrong'), 'error'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => marketingApi.teams.remove(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['marketing-teams'] });
      show(t('notify.marketing.teamDeleted'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.marketing.couldNotDeleteTeam'), 'error'),
  });

  const addMemberMutation = useMutation({
    mutationFn: ({ teamId, adminId }: { teamId: number; adminId: number }) => marketingApi.teams.addMember(teamId, adminId),
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({ queryKey: ['marketing-teams'] });
      setPendingMemberByTeam((prev) => ({ ...prev, [variables.teamId]: '' }));
      show(t('notify.marketing.memberAdded'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.marketing.couldNotAddMember'), 'error'),
  });

  const removeMemberMutation = useMutation({
    mutationFn: ({ teamId, adminId }: { teamId: number; adminId: number }) => marketingApi.teams.removeMember(teamId, adminId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['marketing-teams'] });
      show(t('notify.marketing.memberRemoved'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.marketing.couldNotRemoveMember'), 'error'),
  });

  return (
    <div>
      <PageHeader
        title={t('nav.marketing.teams')}
        breadcrumb={[{ label: t('nav.marketing.marketing'), to: '/marketing' }, { label: t('nav.marketing.teams') }]}
        actions={
          <PermissionGate module="marketing">
            <Button onClick={() => setIsOpen(true)}>
              <Icon name="plus" size={15} />
              {t('action.marketing.addTeam')}
            </Button>
          </PermissionGate>
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
                <PermissionGate module="marketing">
                  <button
                    type="button"
                    onClick={() => deleteMutation.mutate(team.id)}
                    className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-danger-soft hover:text-danger"
                  >
                    <Icon name="trash" size={14} />
                  </button>
                </PermissionGate>
              </div>
              {team.description && <p className="mb-3 text-sm text-ink-muted">{team.description}</p>}
              <div>
                <p className="mb-1.5 text-xs font-medium text-ink-faint">{t('option.marketing.members')}</p>
                {team.members && team.members.length > 0 ? (
                  <div className="flex flex-wrap gap-1.5">
                    {team.members.map((m) => (
                      <span key={m.id} className="flex items-center gap-1 rounded-full bg-primary-soft py-1 pl-2.5 pr-1 text-xs font-medium text-primary">
                        {m.full_name}
                        <PermissionGate module="marketing">
                          <button
                            type="button"
                            onClick={() => removeMemberMutation.mutate({ teamId: team.id, adminId: m.id })}
                            className="flex h-4 w-4 items-center justify-center rounded-full hover:bg-primary/20"
                          >
                            <Icon name="x" size={10} />
                          </button>
                        </PermissionGate>
                      </span>
                    ))}
                  </div>
                ) : (
                  <p className="text-xs text-ink-faint">{t('warning.marketing.noMembers')}</p>
                )}
              </div>
              <PermissionGate module="marketing">
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
              </PermissionGate>
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
            <Button variant="outline" size="sm" onClick={() => setIsOpen(false)}>{t('action.cancel')}</Button>
            <Button size="sm" isLoading={createMutation.isPending} onClick={() => createMutation.mutate()}>{t('action.create')}</Button>
          </>
        }
      >
        <FormField label={t('option.marketing.teamName')} htmlFor="team-name" required>
          <input id="team-name" className={inputClasses} value={name} onChange={(e) => setName(e.target.value)} />
        </FormField>
        <FormField label={t('option.label.description')} htmlFor="team-description">
          <textarea id="team-description" rows={2} className={inputClasses + ' h-auto py-2'} value={description} onChange={(e) => setDescription(e.target.value)} />
        </FormField>
      </Modal>
    </div>
  );
}
