import { useState } from 'react';

import { useI18n } from '@/i18n/useI18n';
import { isCorrectionReasonRequired } from '@/utils/companyEmployees';

/**
 * The "correction reason" step that the server asks for when a change reaches an approved or finalized payroll month (a Super Admin or an HR Manager
 * records it). The field is hidden until the server says it is needed (CORRECTION_REASON_REQUIRED), exactly like the company dialogs; once shown it stays
 * shown and must hold at least 5 characters before the request is sent again. Shared by every Employee Detail workflow that can reach closed payroll.
 */
export function useCorrectionFlow() {
  const { t } = useI18n();
  const [needed, setNeeded] = useState(false);
  const [reason, setReason] = useState('');

  return {
    needed,
    reason,
    setReason,
    /** Call with a failed request's error: true when it was the "reason required" answer (the field is now shown). */
    noteError(error: unknown): boolean {
      if (!isCorrectionReasonRequired(error)) return false;
      setNeeded(true);

      return true;
    },
    /** The message to show under the field, once it is shown and still too short. */
    problem: needed && reason.trim().length < 5 ? t('validation.company.correctionReasonRequired') : undefined,
    /** The part of the request body that carries the reason (empty until the server asked for it). */
    payload: (needed ? { correction_reason: reason.trim() } : {}) as { correction_reason?: string },
  };
}
