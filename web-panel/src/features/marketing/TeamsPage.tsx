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

export function TeamsPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
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
      show('Team created.');
      setIsOpen(false);
      setName('');
      setDescription('');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Something went wrong.', 'error'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => marketingApi.teams.remove(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['marketing-teams'] });
      show('Team deleted.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not delete team.', 'error'),
  });

  const addMemberMutation = useMutation({
    mutationFn: ({ teamId, adminId }: { teamId: number; adminId: number }) => marketingApi.teams.addMember(teamId, adminId),
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({ queryKey: ['marketing-teams'] });
      setPendingMemberByTeam((prev) => ({ ...prev, [variables.teamId]: '' }));
      show('Member added.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not add member.', 'error'),
  });

  const removeMemberMutation = useMutation({
    mutationFn: ({ teamId, adminId }: { teamId: number; adminId: number }) => marketingApi.teams.removeMember(teamId, adminId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['marketing-teams'] });
      show('Member removed.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not remove member.', 'error'),
  });

  return (
    <div>
      <PageHeader
        title="Teams"
        breadcrumb={[{ label: 'Marketing', to: '/marketing' }, { label: 'Teams' }]}
        actions={
          <PermissionGate module="marketing">
            <Button onClick={() => setIsOpen(true)}>
              <Icon name="plus" size={15} />
              Add Team
            </Button>
          </PermissionGate>
        }
      />

      {isLoading && <LoadingState label="Loading teams…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}
      {data && data.length === 0 && <EmptyState icon="users" title="No teams yet" />}

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
                <p className="mb-1.5 text-xs font-medium text-ink-faint">Members</p>
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
                  <p className="text-xs text-ink-faint">No members assigned yet.</p>
                )}
              </div>
              <PermissionGate module="marketing">
                <div className="mt-3 flex items-center gap-1.5 border-t border-border pt-3">
                  <Select
                    value={pendingMemberByTeam[team.id] ?? ''}
                    onChange={(e) => setPendingMemberByTeam((prev) => ({ ...prev, [team.id]: e.target.value }))}
                    placeholder="Add employee…"
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
                    Add
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
        title="Add Team"
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setIsOpen(false)}>Cancel</Button>
            <Button size="sm" isLoading={createMutation.isPending} onClick={() => createMutation.mutate()}>Create</Button>
          </>
        }
      >
        <FormField label="Team name" htmlFor="team-name" required>
          <input id="team-name" className={inputClasses} value={name} onChange={(e) => setName(e.target.value)} />
        </FormField>
        <FormField label="Description" htmlFor="team-description">
          <textarea id="team-description" rows={2} className={inputClasses + ' h-auto py-2'} value={description} onChange={(e) => setDescription(e.target.value)} />
        </FormField>
      </Modal>
    </div>
  );
}
