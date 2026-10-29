import { useMemo, useState } from 'react';
import { NoMatchState, ResetFiltersButton, ResultCount } from '@/components/ui/ListFilterControls';
import { SearchInput } from '@/components/ui/SearchInput';
import { Select } from '@/components/ui/Select';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { MARKETING_STAFF_FILTER_KEYS, filterMarketingStaff, parseMarketingStaffFilters, teamsOf } from '@/utils/marketingStaffFilter';
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
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { useI18n } from '@/i18n/useI18n';
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
  const { t } = useI18n();
  const { admin, isSuperAdmin } = useAuth();
  const { hasPermission } = useEffectivePermissions();
  // Management (inactive people, teams, Add Team Member) is for Super Admin and the Marketing Manager role AND needs the real permission.
  const canSeeManagement = (isSuperAdmin || admin?.role === 'marketing_manager') && hasPermission('marketing.manage');
  const [isAddOpen, setIsAddOpen] = useState(false);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['marketing-employees', canSeeManagement],
    queryFn: canSeeManagement ? marketingApi.employees.listIncludingInactive : marketingApi.employees.list,
  });
  const teams = useQuery({ queryKey: ['marketing-teams'], queryFn: marketingApi.teams.list, enabled: canSeeManagement });

  const manageable = useMemo(() => manageableTeams(teams.data), [teams.data]);

  // Search and filters live in the address bar (Back, a reload or a shared link bring them back). The list is short and arrives whole, so it is
  // searched here; only people the server already allowed this person to see are in it.
  const url = useUrlFilters();
  const filters = parseMarketingStaffFilters((key) => url.get(key));
  const shown = data ? filterMarketingStaff(data, filters) : [];
  const filtered = filters.search.trim() !== '' || filters.role !== '' || filters.team !== '' || filters.status !== '';
  const resetFilters = () => url.reset(MARKETING_STAFF_FILTER_KEYS);
  const teamOptions = teamsOf(data ?? []).map((team) => ({ value: String(team.id), label: team.name }));

  const statusMutation = useMutation({
    mutationFn: ({ employee, status }: { employee: MarketingEmployee; status: 'active' | 'inactive' }) => marketingApi.employees.update(employee.id, { status }),
    onSuccess: (_result, { status }) => {
      queryClient.invalidateQueries({ queryKey: ['marketing-employees'] });
      show(status === 'active' ? t('notify.marketing.accountActivated') : t('notify.marketing.accountDeactivated'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.marketing.somethingWentWrong'), 'error'),
  });

  // The Super Admin and the Marketing Manager manage the members of both teams; the server decides (can_manage_members).
  const canManage = (employee: MarketingEmployee) => employee.role === 'marketing_employee' && manageable.length > 0;

  return (
    <div>
      <PageHeader
        title={t('nav.employees')}
        breadcrumb={[{ label: t('nav.marketing.marketing'), to: '/marketing' }, { label: t('nav.employees') }]}
        actions={
          manageable.length > 0 ? (
            <Button onClick={() => setIsAddOpen(true)}>
              <Icon name="plus" size={15} />
              {t('action.marketing.addTeamMember')}
            </Button>
          ) : undefined
        }
      />

      {data && data.length > 0 && (
        <div className="mb-4 flex flex-col gap-2">
          <div className="flex flex-wrap items-center gap-3">
            <SearchInput value={url.get('q')} onChange={(value) => url.set('q', value)} placeholder={t('option.marketing.searchMarketingStaff')} className="w-full max-w-sm" />
            <Select aria-label={t('option.marketing.filterByRole')} value={filters.role} onChange={(event) => url.set('role', event.target.value)} options={[{ value: 'marketing_manager', label: t('option.marketing.roleManager') }, { value: 'marketing_employee', label: t('option.label.employee') }]} placeholder={t('option.marketing.allRoles')} className="w-44" />
            {teamOptions.length > 0 && (
              <Select aria-label={t('option.marketing.filterByTeam')} value={filters.team} onChange={(event) => url.set('team', event.target.value)} options={teamOptions} placeholder={t('option.marketing.allTeams')} className="w-44" />
            )}
            {canSeeManagement && (
              <Select aria-label={t('option.label.status')} value={filters.status} onChange={(event) => url.set('status', event.target.value)} options={[{ value: 'active', label: t('status.active') }, { value: 'inactive', label: t('status.inactive') }]} placeholder={t('option.marketing.allAccountStatuses')} className="w-44" />
            )}
            <ResetFiltersButton count={url.activeCount(MARKETING_STAFF_FILTER_KEYS)} onReset={resetFilters} />
          </div>
          <ResultCount matching={shown.length} total={data.length} filtered={filtered} />
        </div>
      )}

      <Card>
        {isLoading && <LoadingState label={t('option.marketing.loadingEmployees')} />}
        {error && <ErrorState error={error} onRetry={refetch} />}
        {data && data.length === 0 && (
          <EmptyState icon="users" title={t('warning.marketing.noEmployees')} description={t('warning.marketing.noEmployeesHint')} />
        )}
        {data && data.length > 0 && shown.length === 0 && <NoMatchState onReset={resetFilters} />}
        {data && shown.length > 0 && (
          <div className="divide-y divide-border">
            {shown.map((employee) => (
              <div key={employee.id} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3.5">
                <div className="min-w-0">
                  <p className="text-sm font-medium text-ink">
                    {employee.full_name}
                    {employee.status === 'inactive' && (
                      <span className="ml-2 rounded-full bg-danger-soft px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-danger">{t('status.inactive')}</span>
                    )}
                  </p>
                  <p className="text-xs text-ink-muted">{employee.email}</p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  {employee.teams && employee.teams.length > 0 ? (
                    <div className="flex flex-wrap justify-end gap-1">
                      {employee.teams.map((team) => (
                        <span key={team.id} className="rounded-full bg-surface-alt px-2 py-0.5 text-[11px] text-ink-muted">
                          {team.name}
                        </span>
                      ))}
                    </div>
                  ) : (
                    <span className="text-xs italic text-ink-faint">{t('option.marketing.noTeam')}</span>
                  )}
                  <span className="rounded-full bg-secondary-soft px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-secondary">
                    {employee.role === 'marketing_manager' ? t('option.marketing.roleManager') : t('option.label.employee')}
                  </span>
                  {canManage(employee) && (
                    <Button
                      variant="outline"
                      size="sm"
                      isLoading={statusMutation.isPending && statusMutation.variables?.employee.id === employee.id}
                      onClick={() => statusMutation.mutate({ employee, status: employee.status === 'inactive' ? 'active' : 'inactive' })}
                    >
                      {employee.status === 'inactive' ? t('action.marketing.activate') : t('action.marketing.deactivate')}
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
