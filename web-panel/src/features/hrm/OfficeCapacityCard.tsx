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
import { isInternalOffice } from '@/utils/clientCompanyFilter';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import type { WorkLocation } from '@/types/employee';

/**
 * The Fayadhowr Office's approved headcount, shown and edited on the Office Staff page (it used to be an "Office capacity" button on the
 * Companies page, which mixed an internal location into the client list). The limit is still enforced by the server when someone is assigned
 * to the office, and saving here changes nothing but the capacity.
 */
export function OfficeCapacityCard() {
  const { hasPermission } = useEffectivePermissions();
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [editing, setEditing] = useState<WorkLocation | null>(null);
  const [value, setValue] = useState('');

  const { data: locations } = useQuery({ queryKey: ['work-locations'], queryFn: () => hrApi.workLocations.list() });
  const offices = (locations ?? []).filter(isInternalOffice);

  const save = useMutation({
    mutationFn: () => hrApi.workLocations.update((editing as WorkLocation).id, { capacity: value === '' ? null : Number(value) }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['work-locations'] });
      queryClient.invalidateQueries({ queryKey: ['client-companies'] });
      show('Office capacity saved.');
      setEditing(null);
    },
    onError: (err) => show(friendlyErrorMessage(err, 'Could not save the office capacity.'), 'error'),
  });

  if (offices.length === 0) return null;

  return (
    <>
      {offices.map((office) => (
        <Card key={office.id} className="mb-4">
          <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
            <div>
              <p className="text-sm font-semibold text-ink">{office.name} - approved capacity</p>
              <p className="text-xs text-ink-muted">
                {office.capacity === null ? 'No limit set' : `Capacity ${office.capacity}`} · {office.active_assignments_count} assigned
                {office.available_slots !== null && ` · ${office.available_slots} free`}
              </p>
            </div>
            {hasPermission('hr.manage') && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => {
                  setEditing(office);
                  setValue(office.capacity === null ? '' : String(office.capacity));
                }}
              >
                <Icon name="pencil" size={14} />
                Edit capacity
              </Button>
            )}
          </div>
        </Card>
      ))}

      <Modal
        isOpen={editing !== null}
        onClose={() => setEditing(null)}
        title="Office capacity"
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setEditing(null)}>Cancel</Button>
            <Button size="sm" isLoading={save.isPending} onClick={() => save.mutate()}>Save</Button>
          </>
        }
      >
        <FormField label="Approved employee capacity" htmlFor="office-capacity" hint="Assigning more people than this to the office is refused. Leave empty for no limit.">
          <input id="office-capacity" type="number" min={0} className={inputClasses} value={value} onChange={(e) => setValue(e.target.value)} placeholder="No limit" />
        </FormField>
      </Modal>
    </>
  );
}
