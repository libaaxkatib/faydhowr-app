import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';

import { companyEmployeesApi } from '@/api/companyEmployees';
import { Button } from '@/components/ui/Button';
import { LoadingState } from '@/components/ui/LoadingState';
import { Modal } from '@/components/ui/Modal';
import { ReopenAssignmentDialog } from '@/features/hrm/CorrectionDialogs';
import { ReplaceEmployeeDialog, ReturnEmployeeDialog } from '@/features/hrm/companyEmployees/AssignmentDialogs';
import { useI18n } from '@/i18n/useI18n';
import { assignmentListMonth } from '@/utils/companyEmployees';
import { assignmentLabel, localMonth } from '@/utils/hrHelpers';
import { type EmbeddedWorkflow } from '@/features/hrm/embeddedWorkflow';
import type { Employee } from '@/types/employee';

/**
 * Runs the company-page workflows (Replace Employee, Return Original, Reopen Assignment) from Employee Detail WITHOUT a second copy of their logic: the
 * real row of the assignment is loaded from its company's employees list (the same endpoint the company page uses, for a month the assignment covers)
 * and handed to the very same dialogs. Their validation, correction reason, confirmations, payloads and server answers are therefore identical.
 */
export function EmbeddedAssignmentWorkflows({ workflow, employee, onClose }: { workflow: EmbeddedWorkflow | null; employee: Employee; onClose: () => void }) {
  const { t } = useI18n();
  const assignment = workflow?.assignment ?? null;
  const companyId = assignment?.client_company_id ?? null;
  const period = assignment ? assignmentListMonth(assignment.start_date, assignment.end_date, localMonth()) : '';

  const list = useQuery({
    queryKey: ['company-employees', companyId, 'embedded', assignment?.id, period],
    queryFn: () => companyEmployeesApi.list(companyId as number, { period, search: employee.employee_number }),
    enabled: assignment !== null && companyId !== null,
    // The dialog is filled from this row when it opens; a background refetch must not rewrite what HR is typing.
    staleTime: Infinity,
    gcTime: 0,
  });
  const row = useMemo(() => list.data?.employees.find((candidate) => candidate.assignment_id === assignment?.id) ?? null, [list.data, assignment?.id]);

  if (workflow === null || assignment === null || companyId === null) return null;

  if (row === null) {
    return (
      <Modal
        isOpen
        onClose={onClose}
        title={assignmentLabel(assignment)}
        size="sm"
        footer={<Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>}
      >
        {list.isLoading ? <LoadingState /> : <p role="alert" className="text-sm text-danger">{t('notify.employeeDetail.assignmentRowNotFound')}</p>}
      </Modal>
    );
  }

  const companyName = list.data?.company.name ?? assignment.client_company_name ?? '';

  if (workflow.kind === 'replace') return <ReplaceEmployeeDialog row={row} onClose={onClose} companyId={companyId} companyName={companyName} />;
  if (workflow.kind === 'return') return <ReturnEmployeeDialog row={row} onClose={onClose} companyId={companyId} companyName={companyName} />;

  return <ReopenAssignmentDialog row={row} onClose={onClose} companyId={companyId} companyName={companyName} />;
}
