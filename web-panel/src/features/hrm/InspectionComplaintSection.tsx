import { FormField, inputClasses } from '@/components/ui/FormField';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Select } from '@/components/ui/Select';
import { localToday } from '@/utils/hrHelpers';
import {
  blankComplaint,
  COMPLAINT_STATUS_LABELS,
  type ComplaintAnswer,
  type ComplaintFormState,
  type ComplaintProblems,
  type ComplaintSection,
} from '@/utils/facility';
import type { CompanyEmployeeOption, FacilityComplaintStatus, FacilityComplaintType } from '@/types/facility';

const STATUS_OPTIONS = (['open', 'in_progress', 'answered', 'resolved'] as FacilityComplaintStatus[]).map((value) => ({ value, label: COMPLAINT_STATUS_LABELS[value] }));

const SATISFACTION_OPTIONS = [
  { value: 'satisfied', label: 'Satisfied (documented)' },
  { value: 'not_satisfied', label: 'Not satisfied (documented)' },
];

interface Props {
  type: FacilityComplaintType;
  section: ComplaintSection;
  onChange: (next: ComplaintSection) => void;
  /** People who work for the chosen company (staff complaints pick the reporter from them). */
  employees: CompanyEmployeeOption[];
  companyChosen: boolean;
  /** The inspection date: the default date of each complaint. */
  inspectionDate: string;
  problems: ComplaintProblems;
  showErrors: boolean;
}

/**
 * One of the two complaint sections of the inspection form ("Staff Complaints" / "Customer Complaints"). The inspector must answer
 * "No ... complaint" or "There is a ... complaint"; only the second asks for details, and an incomplete complaint is flagged before saving.
 * Nothing here sets satisfaction by itself: it is only ever the feedback the inspector writes down with its evidence.
 */
export function InspectionComplaintSection({ type, section, onChange, employees, companyChosen, inspectionDate, problems, showErrors }: Props) {
  const staff = type === 'staff';
  const noun = staff ? 'staff' : 'customer';
  const heading = staff ? 'Staff Complaints' : 'Customer Complaints';
  const idBase = `cmp-${type}`;

  const setAnswer = (answer: ComplaintAnswer) => {
    if (answer === 'yes') onChange({ answer, rows: section.rows.length > 0 ? section.rows : [blankComplaint(type, inspectionDate)] });
    else onChange({ answer, rows: [] });
  };
  const setRow = (index: number, patch: Partial<ComplaintFormState>) => onChange({ ...section, rows: section.rows.map((row, i) => (i === index ? { ...row, ...patch } : row)) });
  const err = (index: number, field: string) => (showErrors ? problems[`${index}.${field}`] : undefined);

  return (
    <section className="mb-5 rounded-md border border-border" aria-labelledby={`${idBase}-heading`} data-testid={`${type}-complaints-section`}>
      <div className="border-b border-border bg-surface-alt/50 px-3.5 py-2.5">
        <h3 id={`${idBase}-heading`} className="font-display text-sm font-bold text-ink">{heading}</h3>
      </div>
      <div className="px-3.5 py-3">
        <div role="radiogroup" aria-label={`${heading} - was there one?`} className="flex flex-wrap gap-2">
          {([['none', `No ${noun} complaint`], ['yes', `There is a ${noun} complaint`]] as const).map(([value, label]) => (
            <button
              key={value}
              type="button"
              role="radio"
              aria-checked={section.answer === value}
              onClick={() => setAnswer(value)}
              className={`rounded-full border px-3 py-1 text-xs font-medium transition ${
                section.answer === value ? (value === 'yes' ? 'border-warning bg-warning text-white' : 'border-primary bg-primary text-white') : 'border-border bg-surface text-ink-muted hover:border-primary/40'
              }`}
            >
              {label}
            </button>
          ))}
        </div>
        {showErrors && problems.answer && <p className="mt-1.5 text-xs text-danger">{problems.answer}</p>}
        {section.answer === 'unanswered' && !showErrors && <p className="mt-1.5 text-xs text-ink-faint">Choose one. Nothing is assumed when it is left unanswered.</p>}
        {section.answer === 'none' && <p className="mt-1.5 text-xs text-ink-faint">Recorded as "No {noun} complaint" for this visit.</p>}

        {section.answer === 'yes' &&
          section.rows.map((row, index) => (
            <div key={index} className="mt-3 rounded-md border border-border px-3 py-3" data-testid={`${type}-complaint-${index}`}>
              <div className="mb-2 flex items-center justify-between">
                <p className="text-xs font-semibold uppercase tracking-wide text-ink-muted">{heading.slice(0, -1)} {index + 1}</p>
                {section.rows.length > 1 && (
                  <button type="button" onClick={() => onChange({ ...section, rows: section.rows.filter((_, i) => i !== index) })} className="text-xs font-semibold text-danger hover:underline">
                    Remove
                  </button>
                )}
              </div>

              <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
                {staff ? (
                  <FormField label="Employee who complained" htmlFor={`${idBase}-${index}-employee`} required error={err(index, 'employeeId')} hint={companyChosen ? 'Only people who work for the chosen company are listed.' : 'Choose the company first.'}>
                    <Select
                      id={`${idBase}-${index}-employee`}
                      value={row.employeeId}
                      disabled={!companyChosen}
                      onChange={(e) => setRow(index, { employeeId: e.target.value })}
                      placeholder="Choose the employee…"
                      options={employees.map((e) => ({ value: String(e.id), label: `${e.full_name} (${e.employee_number})` }))}
                    />
                  </FormField>
                ) : (
                  <>
                    <FormField label="Customer name" htmlFor={`${idBase}-${index}-name`} required error={err(index, 'reporterName')}>
                      <input id={`${idBase}-${index}-name`} className={inputClasses} value={row.reporterName} onChange={(e) => setRow(index, { reporterName: e.target.value })} />
                    </FormField>
                    <FormField label="Role / relation (optional)" htmlFor={`${idBase}-${index}-role`} hint="For example: hotel manager, guest, household owner.">
                      <input id={`${idBase}-${index}-role`} className={inputClasses} value={row.reporterType} onChange={(e) => setRow(index, { reporterType: e.target.value })} />
                    </FormField>
                    <FormField label="Customer phone (optional)" htmlFor={`${idBase}-${index}-phone`}>
                      <input id={`${idBase}-${index}-phone`} className={inputClasses} value={row.reporterPhone} onChange={(e) => setRow(index, { reporterPhone: e.target.value })} />
                    </FormField>
                  </>
                )}
                <FormField label="Responsible person or team" htmlFor={`${idBase}-${index}-responsible`} required error={err(index, 'responsiblePerson')}>
                  <input id={`${idBase}-${index}-responsible`} className={inputClasses} value={row.responsiblePerson} onChange={(e) => setRow(index, { responsiblePerson: e.target.value })} />
                </FormField>
                <FormField label="Date of the complaint" htmlFor={`${idBase}-${index}-date`}>
                  <input id={`${idBase}-${index}-date`} type="date" max={localToday()} className={inputClasses} value={row.reportedOn} onChange={(e) => setRow(index, { reportedOn: e.target.value })} />
                </FormField>
                <FormField label="Status" htmlFor={`${idBase}-${index}-status`} hint="Answered means a reply was given; it is not the same as Resolved.">
                  <Select id={`${idBase}-${index}-status`} value={row.status} onChange={(e) => setRow(index, { status: e.target.value as FacilityComplaintStatus })} options={STATUS_OPTIONS} />
                </FormField>
              </div>

              <FormField label="Complaint" htmlFor={`${idBase}-${index}-details`} required error={err(index, 'details')}>
                <textarea id={`${idBase}-${index}-details`} rows={2} className={`${inputClasses} h-auto py-2`} value={row.details} onChange={(e) => setRow(index, { details: e.target.value })} />
              </FormField>
              <FormField label="Response given" htmlFor={`${idBase}-${index}-response`} error={err(index, 'response')}>
                <textarea id={`${idBase}-${index}-response`} rows={2} className={`${inputClasses} h-auto py-2`} value={row.response} onChange={(e) => setRow(index, { response: e.target.value })} />
              </FormField>
              <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
                <FormField label="Response date" htmlFor={`${idBase}-${index}-responded`} error={err(index, 'respondedOn')}>
                  <input id={`${idBase}-${index}-responded`} type="date" className={inputClasses} value={row.respondedOn} onChange={(e) => setRow(index, { respondedOn: e.target.value })} />
                </FormField>
                <FormField label="Follow-up date" htmlFor={`${idBase}-${index}-followup`} error={err(index, 'followUpOn')}>
                  <input id={`${idBase}-${index}-followup`} type="date" className={inputClasses} value={row.followUpOn} onChange={(e) => setRow(index, { followUpOn: e.target.value })} />
                </FormField>
              </div>
              <FormField label="Corrective action" htmlFor={`${idBase}-${index}-action`} error={err(index, 'correctiveAction')} hint="Required once the complaint is answered or resolved.">
                <textarea id={`${idBase}-${index}-action`} rows={2} className={`${inputClasses} h-auto py-2`} value={row.correctiveAction} onChange={(e) => setRow(index, { correctiveAction: e.target.value })} />
              </FormField>
              {row.status === 'resolved' && (
                <FormField label="How it was resolved" htmlFor={`${idBase}-${index}-note`} required error={err(index, 'statusNote')}>
                  <input id={`${idBase}-${index}-note`} className={inputClasses} value={row.statusNote} onChange={(e) => setRow(index, { statusNote: e.target.value })} />
                </FormField>
              )}

              <div className="rounded-sm bg-surface-alt/60 px-3 py-2">
                <p className="mb-1.5 text-xs text-ink-muted">Satisfaction is only recorded when the {noun} actually gave feedback. Leave it empty otherwise - it stays "Not recorded".</p>
                <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
                  <FormField label="Feedback (optional)" htmlFor={`${idBase}-${index}-satisfaction`} error={err(index, 'satisfaction')}>
                    <Select id={`${idBase}-${index}-satisfaction`} value={row.satisfaction} onChange={(e) => setRow(index, { satisfaction: e.target.value as ComplaintFormState['satisfaction'] })} placeholder="Not recorded" options={SATISFACTION_OPTIONS} />
                  </FormField>
                  {row.satisfaction !== '' && (
                    <FormField label="Evidence of the feedback" htmlFor={`${idBase}-${index}-evidence`} required error={err(index, 'satisfactionEvidence')} hint="Who said it, how and when.">
                      <input id={`${idBase}-${index}-evidence`} className={inputClasses} value={row.satisfactionEvidence} onChange={(e) => setRow(index, { satisfactionEvidence: e.target.value })} />
                    </FormField>
                  )}
                </div>
              </div>
            </div>
          ))}

        {section.answer === 'yes' && (
          <div className="mt-3">
            <Button variant="outline" size="sm" onClick={() => onChange({ ...section, rows: [...section.rows, blankComplaint(type, inspectionDate)] })}>
              <Icon name="plus" size={14} />
              Add another {noun} complaint
            </Button>
          </div>
        )}
      </div>
    </section>
  );
}
