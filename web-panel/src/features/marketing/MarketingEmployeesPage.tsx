import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { useToast } from '@/components/ui/useToast';
import { useAuth } from '@/features/auth/useAuth';
import { AddTeamMemberDialog } from '@/features/marketing/AddTeamMemberDialog';
import { manageableTeams } from '@/utils/teamMemberForm';
import type { MarketingEmployee } from '@/types/marketing';

/**
 * Directory of Marketing Manager / Marketing Employee accounts, per docs/HRM_MARKETING_SRS.md §3/§4.2. It reuses the existing
 * Admin entity (GET /admin/marketing/employees) rather than a separate employee model.
 *
 * "Add Team Member" and the activate/deactivate action are shown only to the Super Admin and the Marketing Manager (who manage the
 * members of both teams) - mirrored from the server's can_manage_members flag. Hiding a button is not the security: the server
 * refuses the same calls for everyone else.
 */
export function MarketingEmployeesPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { admin, isSuperAdmin } = useAuth();
  const canSeeManagement = isSuperAdmin || admin?.role === 'marketing_manager';
  const [isAddOpen, setIsAddOpen] = useState(false);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['marketing-employees', canSeeManagement],
    queryFn: canSeeManagement ? marketingApi.employees.listIncludingInactive : marketingApi.employees.list,
  });
  const teams = useQuery({ queryKey: ['marketing-teams'], queryFn: marketingApi.teams.list, enabled: canSeeManagement });

  const manageable = useMemo(() => manageableTeams(teams.data), [teams.data]);

  const statusMutation = useMutation({
    mutationFn: ({ employee, status }: { employee: MarketingEmployee; status: 'active' | 'inactive' }) => marketingApi.employees.update(employee.id, { status }),
    onSuccess: (_result, { status }) => {
      queryClient.invalidateQueries({ queryKey: ['marketing-employees'] });
      show(status === 'active' ? 'Account activated.' : 'Account deactivated.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Something went wrong.', 'error'),
  });

  // The Super Admin and the Marketing Manager manage the members of both teams; the server decides (can_manage_members).
  const canManage = (employee: MarketingEmployee) => employee.role === 'marketing_employee' && manageable.length > 0;

  return (
    <div>
      <PageHeader
        title="Employees"
        breadcrumb={[{ label: 'Marketing', to: '/marketing' }, { label: 'Employees' }]}
        actions={
          manageable.length > 0 ? (
            <Button onClick={() => setIsAddOpen(true)}>
              <Icon name="plus" size={15} />
              Add Team Member
            </Button>
          ) : undefined
        }
      />

      <Card>
        {isLoading && <LoadingState label="Loading marketing employees…" />}
        {error && <ErrorState error={error} onRetry={refetch} />}
        {data && data.length === 0 && (
          <EmptyState icon="users" title="No marketing employees yet" description="Use Add Team Member to create the first account for a team." />
        )}
        {data && data.length > 0 && (
          <div className="divide-y divide-border">
            {data.map((employee) => (
              <div key={employee.id} className="flex items-center justify-between px-5 py-3.5">
                <div>
                  <p className="text-sm font-medium text-ink">
                    {employee.full_name}
                    {employee.status === 'inactive' && (
                      <span className="ml-2 rounded-full bg-danger-soft px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-danger">Inactive</span>
                    )}
                  </p>
                  <p className="text-xs text-ink-muted">{employee.email}</p>
                </div>
                <div className="flex items-center gap-2">
                  {employee.teams && employee.teams.length > 0 ? (
                    <div className="flex flex-wrap justify-end gap-1">
                      {employee.teams.map((team) => (
                        <span key={team.id} className="rounded-full bg-surface-alt px-2 py-0.5 text-[11px] text-ink-muted">
                          {team.name}
                        </span>
                      ))}
                    </div>
                  ) : (
                    <span className="text-xs italic text-ink-faint">No team</span>
                  )}
                  <span className="rounded-full bg-secondary-soft px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-secondary">
                    {employee.role === 'marketing_manager' ? 'Manager' : 'Employee'}
                  </span>
                  {canManage(employee) && (
                    <Button
                      variant="outline"
                      size="sm"
                      isLoading={statusMutation.isPending && statusMutation.variables?.employee.id === employee.id}
                      onClick={() => statusMutation.mutate({ employee, status: employee.status === 'inactive' ? 'active' : 'inactive' })}
                    >
                      {employee.status === 'inactive' ? 'Activate' : 'Deactivate'}
                    </Button>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      <AddTeamMemberDialog isOpen={isAddOpen} onClose={() => setIsAddOpen(false)} teams={manageable} />
    </div>
  );
}
