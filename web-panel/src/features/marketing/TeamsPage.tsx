import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
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

  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['marketing-teams'], queryFn: marketingApi.teams.list });

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
                      <span key={m.id} className="rounded-full bg-primary-soft px-2.5 py-1 text-xs font-medium text-primary">
                        {m.full_name}
                      </span>
                    ))}
                  </div>
                ) : (
                  <p className="text-xs text-ink-faint">No members assigned yet.</p>
                )}
              </div>
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
