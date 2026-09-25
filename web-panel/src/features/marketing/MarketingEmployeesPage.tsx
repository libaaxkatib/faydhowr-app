import { useQuery } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';

/**
 * Read-only directory of Marketing Manager / Marketing Employee accounts,
 * per docs/HRM_MARKETING_SRS.md §3/§4.2. Reuses the existing Admin entity
 * (via GET /admin/marketing/employees, scoped to the two marketing roles)
 * rather than a separate employee model — account creation/editing stays
 * under Super Admin's existing Admins screen.
 */
export function MarketingEmployeesPage() {
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['marketing-employees'], queryFn: marketingApi.employees.list });

  return (
    <div>
      <PageHeader title="Employees" breadcrumb={[{ label: 'Marketing', to: '/marketing' }, { label: 'Employees' }]} />

      <Card>
        {isLoading && <LoadingState label="Loading marketing employees…" />}
        {error && <ErrorState error={error} onRetry={refetch} />}
        {data && data.length === 0 && <EmptyState icon="users" title="No marketing employees yet" description="Grant the Marketing Manager or Marketing Employee role to an admin account to see them here." />}
        {data && data.length > 0 && (
          <div className="divide-y divide-border">
            {data.map((employee) => (
              <div key={employee.id} className="flex items-center justify-between px-5 py-3.5">
                <div>
                  <p className="text-sm font-medium text-ink">{employee.full_name}</p>
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
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}
