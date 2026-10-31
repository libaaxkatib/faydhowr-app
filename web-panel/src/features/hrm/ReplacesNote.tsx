import { Link } from 'react-router-dom';

import { useI18n } from '@/i18n/useI18n';
import type { WorkAssignment } from '@/types/employee';
import { assignmentReplacesDisplay } from '@/utils/replacedEmployee';

/**
 * Under a Replacement assignment on the employee detail: "Replaces: Name (EMP-number)", the name linking to that employee, from the recorded link only.
 * A Replacement that replaces nobody on record says so; a Permanent assignment (or a link whose details did not arrive) shows nothing.
 */
export function ReplacesNote({ assignment }: { assignment: Pick<WorkAssignment, 'assignment_type' | 'replaces_assignment_id' | 'replaces'> }) {
  const { t } = useI18n();
  const display = assignmentReplacesDisplay(assignment);

  if (display.kind === 'none') return null;

  if (display.kind === 'missing') {
    return (
      <p className="mt-1 text-xs muted-note" data-testid="replaces-missing">
        {t('option.company.replacesNotRecorded')}
      </p>
    );
  }

  return (
    <p className="mt-1 text-xs text-ink-muted" data-testid="replaces-line">
      {t('option.company.replacesLabel')}{' '}
      <Link to={`/hr/employees/${display.employeeId}`} className="font-medium text-ink hover:text-primary hover:underline">
        {display.name ?? `${t('option.label.employeeId')} ${display.employeeId}`}
      </Link>
      {display.number !== null && ` (${display.number})`}
    </p>
  );
}
