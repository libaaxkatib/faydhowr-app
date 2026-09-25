import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { inputClasses } from '@/components/ui/FormField';
import type { AttendanceStatus } from '@/types/employee';

const STATUS_TONE: Record<AttendanceStatus, 'success' | 'warning' | 'danger'> = {
  present: 'success',
  late: 'warning',
  absent: 'danger',
};

const STATUS_LABEL: Record<AttendanceStatus, string> = {
  present: 'Present',
  late: 'Late',
  absent: 'Absent',
};

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 4: the daily Office Staff roster - a
 * company-wide ritual, so it gets its own page rather than a per-employee
 * modal on the Employee Profile. Marking is an upsert: re-marking the same
 * day corrects it in place (see MarkEmployeeAttendanceAction).
 */
export function AttendancePage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['attendance', date],
    queryFn: () => hrApi.attendance.forDate(date),
  });

  const markMutation = useMutation({
    mutationFn: ({ employeeId, status }: { employeeId: number; status: AttendanceStatus }) =>
      hrApi.employees.markAttendance(employeeId, { date, status }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['attendance', date] });
      queryClient.invalidateQueries({ queryKey: ['hr-dashboard'] });
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not record attendance.', 'error'),
  });

  return (
    <div>
      <PageHeader title="Attendance" breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Attendance' }]} />

      <Card>
        <div className="flex items-center gap-3 border-b border-border px-5 py-4">
          <label htmlFor="attendance-date" className="text-xs font-medium text-ink-faint">
            Date
          </label>
          <input id="attendance-date" type="date" className={`${inputClasses} w-44`} value={date} onChange={(e) => setDate(e.target.value)} />
        </div>

        {isLoading && <LoadingState label="Loading roster…" />}
        {error && <ErrorState error={error} onRetry={refetch} />}
        {data && data.length === 0 && <EmptyState icon="users" title="No Office Staff yet" description="Assign an employee to Fayadhowr Office to see them here." />}

        {data && data.length > 0 && (
          <div className="divide-y divide-border">
            {data.map((entry) => (
              <div key={entry.id} className="flex items-center justify-between gap-3 px-5 py-3">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium text-ink">{entry.full_name}</p>
                  <p className="truncate text-xs text-ink-muted">
                    {entry.employee_number} {entry.position_name && `· ${entry.position_name}`}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  {entry.attendance_status ? (
                    <StatusBadge status={entry.attendance_status} label={STATUS_LABEL[entry.attendance_status]} tone={STATUS_TONE[entry.attendance_status]} />
                  ) : (
                    <span className="text-xs italic text-ink-faint">Not marked</span>
                  )}
                  <PermissionGate module="hr">
                    {(['present', 'late', 'absent'] as AttendanceStatus[]).map((status) => (
                      <Button
                        key={status}
                        size="sm"
                        variant={entry.attendance_status === status ? 'primary' : 'outline'}
                        className="h-8 px-2.5 text-xs"
                        isLoading={markMutation.isPending && markMutation.variables?.employeeId === entry.id && markMutation.variables?.status === status}
                        onClick={() => markMutation.mutate({ employeeId: entry.id, status })}
                      >
                        {STATUS_LABEL[status]}
                      </Button>
                    ))}
                  </PermissionGate>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}
