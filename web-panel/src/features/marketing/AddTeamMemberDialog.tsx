import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { ApiClientError } from '@/api/client';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/useToast';
import { useI18n } from '@/i18n/useI18n';
import { buildAddMemberPayload, emptyTeamMemberForm, MIN_PASSWORD_LENGTH, validateTeamMemberForm, type TeamMemberForm } from '@/utils/teamMemberForm';
import type { MarketingTeam } from '@/types/marketing';

interface AddTeamMemberDialogProps {
  isOpen: boolean;
  onClose: () => void;
  /** Teams the signed-in user may add members to (from the server's can_manage_members flag). */
  teams: MarketingTeam[];
}

/**
 * Add Team Member. Creates a Marketing Employee account in one team. The role is not a field: the server fixes it. The initial
 * password is set here and handed to the member, exactly like the existing admin creation; the member can change it after
 * signing in.
 */
export function AddTeamMemberDialog({ isOpen, onClose, teams }: AddTeamMemberDialogProps) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t } = useI18n();
  const [form, setForm] = useState<TeamMemberForm>(emptyTeamMemberForm);
  const [clientErrors, setClientErrors] = useState<Partial<Record<keyof TeamMemberForm, string>>>({});
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});

  // Reset only when the dialog opens (or its single possible team changes) - never on a mere re-render or refetch, which would
  // wipe what the user is typing.
  const onlyTeamId: number | '' = teams.length === 1 ? teams[0].id : '';

  useEffect(() => {
    if (isOpen) {
      setForm({ ...emptyTeamMemberForm, team_id: onlyTeamId });
      setClientErrors({});
      setServerErrors({});
    }
  }, [isOpen, onlyTeamId]);

  const mutation = useMutation({
    mutationFn: () => marketingApi.employees.create(buildAddMemberPayload(form)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['marketing-employees'] });
      queryClient.invalidateQueries({ queryKey: ['marketing-teams'] });
      show(t('notify.marketing.teamMemberAdded'));
      onClose();
    },
    onError: (error) => {
      if (error instanceof ApiClientError && error.isValidation) {
        setServerErrors(error.fieldErrors ?? {});
      } else {
        show(error instanceof Error ? error.message : t('notify.marketing.somethingWentWrong'), 'error');
      }
    },
  });

  function handleSubmit(event: FormEvent) {
    event.preventDefault();
    const errors = validateTeamMemberForm(form);
    setClientErrors(errors);
    setServerErrors({});
    if (Object.keys(errors).length === 0) mutation.mutate();
  }

  const error = (field: keyof TeamMemberForm) => clientErrors[field] ?? serverErrors[field]?.[0];

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={t('action.marketing.addTeamMember')}
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>{t('action.cancel')}</Button>
          <Button size="sm" onClick={handleSubmit} isLoading={mutation.isPending}>{t('action.marketing.addTeamMember')}</Button>
        </>
      }
    >
      <form onSubmit={handleSubmit} noValidate>
        <FormField label={t('option.marketing.team')} htmlFor="member-team" required error={error('team_id')}>
          <Select
            id="member-team"
            value={form.team_id === '' ? '' : String(form.team_id)}
            onChange={(event) => setForm({ ...form, team_id: event.target.value === '' ? '' : Number(event.target.value) })}
            placeholder={t('option.marketing.chooseTeam')}
            options={teams.map((team) => ({ value: String(team.id), label: team.name }))}
          />
        </FormField>
        <FormField label={t('option.label.fullName')} htmlFor="member-full-name" required error={error('full_name')}>
          <input id="member-full-name" className={inputClasses} value={form.full_name} onChange={(event) => setForm({ ...form, full_name: event.target.value })} />
        </FormField>
        <div className="grid grid-cols-2 gap-3">
          <FormField label={t('option.marketing.email')} htmlFor="member-email" required error={error('email')}>
            <input id="member-email" type="email" className={inputClasses} value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} />
          </FormField>
          <FormField label={t('option.label.phone')} htmlFor="member-phone" required error={error('phone')}>
            <input id="member-phone" className={inputClasses} value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} />
          </FormField>
        </div>
        <FormField label={t('option.marketing.initialPassword')} htmlFor="member-password" required error={error('password')} hint={t('warning.marketing.passwordHint', { min: MIN_PASSWORD_LENGTH })}>
          <input id="member-password" type="password" autoComplete="new-password" className={inputClasses} value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} />
        </FormField>
        <p className="text-xs text-ink-muted">{t('warning.marketing.accountCreatedNote')}</p>
      </form>
    </Modal>
  );
}
