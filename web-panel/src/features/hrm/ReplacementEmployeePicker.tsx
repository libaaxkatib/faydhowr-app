import { useState } from 'react';
import { useInfiniteQuery } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Select';
import { inputClasses } from '@/components/ui/FormField';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import type { EmploymentType } from '@/types/employee';
import { useI18n } from '@/i18n/useI18n';
import { foundButNoneChoosable, hasMore, isSearching, pickerOptions, pickerParams, type NotChoosable } from '@/utils/employeePicker';

/**
 * Searchable list of employees for the Temporary replacement forms (the person doing the cover: Temporary; the person being covered: Permanent).
 *  - Nothing typed: the people who can be chosen (Active, of this type), a page at a time.
 *  - Something typed: the server searches EVERY employee by name, employee number, phone or database ID, so the person being looked for is always found,
 *    whatever their status or type. Whoever cannot be chosen is shown greyed out with the reason; only people who meet the rules can be selected.
 * "Show more" loads the next page of the current search; a new search starts again from its first page.
 * `current` keeps a person that is already chosen (for example the saved Temporary employee of a cover that is being corrected) in the list.
 * The rules are the server's and are re-checked on save: finding a person here never makes them eligible.
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
  const { t, tValue } = useI18n();
  const [search, setSearch] = useState('');
  const [selectedLabel, setSelectedLabel] = useState(current?.label ?? '');
  const debouncedSearch = useDebouncedValue(search, 350);
  const searching = isSearching(debouncedSearch);

  const { data, isFetching, isFetchingNextPage, hasNextPage, fetchNextPage } = useInfiniteQuery({
    queryKey: ['employees', 'replacement-picker', employmentType, debouncedSearch.trim()],
    queryFn: ({ pageParam }) => hrApi.employees.list(pickerParams(employmentType, debouncedSearch, pageParam)),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
  });

  const reasonText = (why: NotChoosable): string => {
    switch (why.reason) {
      case 'covered_person':
        return t('option.pipeline.cannotChoose.coveredPerson');
      case 'not_active':
        return t('option.pipeline.cannotChoose.notActive', { status: tValue('status', why.status) ?? why.status });
      case 'not_temporary':
        return t('option.pipeline.cannotChoose.notTemporary');
      case 'not_permanent':
        return t('option.pipeline.cannotChoose.isTemporary');
      case 'registration_incomplete':
        return t('option.pipeline.registrationNotComplete');
    }
  };

  const employees = (data?.pages ?? []).flatMap((page) => page.data);
  const total = data?.pages[0]?.meta.total ?? 0;
  const options = pickerOptions(employees, { employmentType, excludeId, reasonText });

  // Keep the chosen person visible even after the search box narrows the list to someone else.
  if (value !== '' && selectedLabel !== '' && !options.some((option) => option.value === value)) {
    options.unshift({ value, label: selectedLabel });
  }

  const emptyText = searching
    ? t('option.pipeline.noEmployeeMatchesSearch')
    : employmentType === 'permanent'
      ? t('option.pipeline.noPermanentEmployeesFound')
      : t('option.pipeline.noTemporaryEmployeesFound');

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
      {searching && (
        <p className="text-xs text-ink-muted" data-testid={`${id}-search-note`}>
          {employmentType === 'temporary' ? t('option.pipeline.searchFindsAnyoneTemporary') : t('option.pipeline.searchFindsAnyonePermanent')}
        </p>
      )}
      <Select
        id={id}
        value={value}
        disabled={disabled}
        onChange={(e) => {
          onChange(e.target.value);
          setSelectedLabel(options.find((option) => option.value === e.target.value)?.label ?? '');
        }}
        placeholder={isFetching && !isFetchingNextPage ? t('option.label.searching') : options.length === 0 ? emptyText : placeholder}
        options={options}
      />
      {searching && !isFetching && foundButNoneChoosable(options) && (
        <p className="text-xs font-medium text-ink" role="status" data-testid={`${id}-none-choosable`}>
          {t('option.pipeline.foundNoneChoosable', { count: options.length })}
        </p>
      )}
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
