import { useState } from 'react';
import { useInfiniteQuery } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Select';
import { inputClasses } from '@/components/ui/FormField';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import type { EmploymentType } from '@/types/employee';
import { useI18n } from '@/i18n/useI18n';
import { hasMore, pickerOptions, pickerParams } from '@/utils/employeePicker';

/**
 * Searchable list of Active employees of one employment type. The type, the search and the paging are done by the server, so nobody is hidden because
 * they fall beyond the first rows: a typed name, employee number or database ID narrows the list, and "Show more" loads the next page.
 * `current` keeps a person that is already chosen (for example the saved Temporary employee of a cover that is being corrected) in the list.
 * Who may actually cover is still decided by the server on save; a Temporary person whose registration is not complete is listed but cannot be chosen.
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

  const { data, isFetching, isFetchingNextPage, hasNextPage, fetchNextPage } = useInfiniteQuery({
    queryKey: ['employees', 'replacement-picker', employmentType, debouncedSearch.trim()],
    queryFn: ({ pageParam }) => hrApi.employees.list(pickerParams(employmentType, debouncedSearch, pageParam)),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
  });

  const employees = (data?.pages ?? []).flatMap((page) => page.data);
  const total = data?.pages[0]?.meta.total ?? 0;
  const options = pickerOptions(employees, { employmentType, excludeId, notReadyLabel: () => t('option.pipeline.registrationNotComplete') });

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
        placeholder={t('option.pipeline.searchEmployeesByIdPlaceholder')}
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
          isFetching && !isFetchingNextPage
            ? t('option.label.searching')
            : options.length === 0
              ? employmentType === 'permanent'
                ? t('option.pipeline.noPermanentEmployeesFound')
                : t('option.pipeline.noTemporaryEmployeesFound')
              : placeholder
        }
        options={options}
      />
      {hasMore(employees.length, total) && (
        <div className="flex items-center justify-between gap-2" data-testid={`${id}-more`}>
          <span className="text-xs text-ink-muted">{t('option.pipeline.employeesShown', { shown: employees.length, total })}</span>
          {hasNextPage && (
            <Button type="button" variant="outline" size="sm" isLoading={isFetchingNextPage} onClick={() => fetchNextPage()} disabled={disabled}>
              {t('option.pipeline.showMoreEmployees')}
            </Button>
          )}
        </div>
      )}
    </div>
  );
}
