import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/useToast';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { useI18n } from '@/i18n/useI18n';
import { isInternalOffice } from '@/utils/clientCompanyFilter';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import type { WorkLocation } from '@/types/employee';

/**
 * The Fayadhowr Office's name and approved headcount, shown and edited on the Office Staff page (it used to be an "Office capacity" button on the
 * Companies page, which mixed an internal location into the client list). The limit is still enforced by the server when someone is assigned
 * to the office. Saving changes only the name and the capacity: the office keeps its id, the people assigned to it and its history, and the
 * server refuses a blank or duplicate name and writes the rename to the audit log.
 */
export function OfficeCapacityCard() {
  const { hasPermission } = useEffectivePermissions();
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t } = useI18n();
  const [editing, setEditing] = useState<WorkLocation | null>(null);
  const [value, setValue] = useState('');
  const [nameValue, setNameValue] = useState('');

  const { data: locations } = useQuery({ queryKey: ['work-locations'], queryFn: () => hrApi.workLocations.list() });
  const offices = (locations ?? []).filter(isInternalOffice);

  const save = useMutation({
    mutationFn: () => {
      const current = editing as WorkLocation;
      const newName = nameValue.trim();
      // The name is sent only when it was changed, so a capacity-only edit never touches it.
      return hrApi.workLocations.update(current.id, {
        ...(newName !== '' && newName !== current.name ? { name: newName } : {}),
        capacity: value === '' ? null : Number(value),
      });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['work-locations'] });
      queryClient.invalidateQueries({ queryKey: ['client-companies'] });
      show(t('notify.company.officeSaved'));
      setEditing(null);
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.company.couldNotSaveOffice')), 'error'),
  });

  if (offices.length === 0) return null;

  return (
    <>
      {offices.map((office) => (
        <Card key={office.id} className="mb-4">
          <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
            <div>
              <p className="text-sm font-semibold text-ink">{t('option.employees.officeCapacityTitle', { name: office.name })}</p>
              <p className="text-xs text-ink-muted">
                {office.capacity === null ? t('option.employees.officeNoLimitSet') : t('option.employees.officeCapacity', { capacity: office.capacity })} · {t('option.employees.officeAssigned', { count: office.active_assignments_count })}
                {office.available_slots !== null && ` · ${t('option.employees.officeFree', { count: office.available_slots })}`}
              </p>
            </div>
            {hasPermission('hr.manage') && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => {
                  setEditing(office);
                  setNameValue(office.name);
                  setValue(office.capacity === null ? '' : String(office.capacity));
                }}
              >
                <Icon name="pencil" size={14} />
                {t('action.company.editOffice')}
              </Button>
            )}
          </div>
        </Card>
      ))}

      <Modal
        isOpen={editing !== null}
        onClose={() => setEditing(null)}
        title={t('confirm.company.editOfficeTitle')}
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setEditing(null)}>{t('action.cancel')}</Button>
            <Button size="sm" isLoading={save.isPending} disabled={nameValue.trim() === ''} onClick={() => save.mutate()}>{t('action.save')}</Button>
          </>
        }
      >
        <FormField label={t('option.label.name')} htmlFor="office-name" required hint={t('confirm.company.officeNameHint')}>
          <input id="office-name" className={inputClasses} value={nameValue} onChange={(e) => setNameValue(e.target.value)} />
        </FormField>
        <FormField label={t('option.employees.officeApprovedCapacity')} htmlFor="office-capacity" hint={t('confirm.company.officeCapacityHint')}>
          <input id="office-capacity" type="number" min={0} className={inputClasses} value={value} onChange={(e) => setValue(e.target.value)} placeholder={t('option.label.noLimit')} />
        </FormField>
      </Modal>
    </>
  );
}
