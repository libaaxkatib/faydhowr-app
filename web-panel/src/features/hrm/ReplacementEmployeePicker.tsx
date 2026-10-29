import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Select } from '@/components/ui/Select';
import { inputClasses } from '@/components/ui/FormField';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import type { EmploymentType } from '@/types/employee';
import { useI18n } from '@/i18n/useI18n';

/**
 * Searchable list of Active employees of one employment type. The list endpoint
 * has no employment-type filter, so the type is filtered here after searching.
 * `current` keeps a person that is already chosen (for example the saved Temporary employee of a cover that is being corrected) in the list.
 */
export function EmployeePicker({
  id,
  employmentType,
  value,
  onChange,
  excludeId,
  placeholder,
  current,
  disabled,
}: {
  id: string;
  employmentType: EmploymentType;
  value: string;
  onChange: (employeeId: string) => void;
  excludeId?: string;
  placeholder: string;
  current?: { value: string; label: string };
  disabled?: boolean;
}) {
  const { t } = useI18n();
  const [search, setSearch] = useState('');
  const [selectedLabel, setSelectedLabel] = useState(current?.label ?? '');
  const debouncedSearch = useDebouncedValue(search, 350);

  const { data, isFetching } = useQuery({
    queryKey: ['employees', { status: 'active', for: 'replacement-picker', search: debouncedSearch }],
    queryFn: () => hrApi.employees.list({ status: 'active', search: debouncedSearch || undefined, per_page: 100 }),
  });

  const options = (data?.data ?? [])
    .filter((employee) => employee.employment_type === employmentType && String(employee.id) !== excludeId)
    .map((employee) => ({ value: String(employee.id), label: `${employee.full_name} (${employee.employee_number})` }));

  // Keep the chosen person visible even after the search box narrows the list to someone else.
  if (value !== '' && selectedLabel !== '' && !options.some((option) => option.value === value)) {
    options.unshift({ value, label: selectedLabel });
  }

  return (
    <div className="space-y-1.5">
      <input
        type="search"
        className={inputClasses}
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        placeholder={t('option.pipeline.searchEmployeesPlaceholder')}
        aria-label={t('option.pipeline.searchEmployees')}
        disabled={disabled}
      />
      <Select
        id={id}
        value={value}
        disabled={disabled}
        onChange={(e) => {
          onChange(e.target.value);
          setSelectedLabel(options.find((option) => option.value === e.target.value)?.label ?? '');
        }}
        placeholder={
          isFetching
            ? t('option.label.searching')
            : options.length === 0
              ? employmentType === 'permanent'
                ? t('option.pipeline.noPermanentEmployeesFound')
                : t('option.pipeline.noTemporaryEmployeesFound')
              : placeholder
        }
        options={options}
      />
    </div>
  );
}
