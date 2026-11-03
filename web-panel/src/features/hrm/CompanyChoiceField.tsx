import { FormField } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { useI18n } from '@/i18n/useI18n';
import { choiceValue, type CompanyChoice } from '@/utils/inputCompany';

/**
 * "Which company is this for?" - shown only when the employee works for more than one company. Each company has its own payroll, so a penalty,
 * advance, unpaid leave or absence is charged to the company chosen here and to no other.
 */
export function CompanyChoiceField({
  id,
  choices,
  value,
  onChange,
}: {
  id: string;
  choices: readonly CompanyChoice[];
  value: string;
  onChange: (value: string) => void;
}) {
  const { t } = useI18n();

  if (choices.length < 2) return null;

  return (
    <FormField label={t('option.employeeDetail.fieldCompany')} htmlFor={id} required>
      <Select
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={t('option.employeeDetail.chooseCompany')}
        options={choices.map((choice) => ({ value: choiceValue(choice), label: choice.name }))}
      />
      <p className="mt-1 text-xs text-ink-muted">{t('option.employeeDetail.companyOwnsPayroll')}</p>
    </FormField>
  );
}
