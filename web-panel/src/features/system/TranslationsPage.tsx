import { useMemo, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ApiClientError } from '@/api/client';
import { translationOverridesApi } from '@/api/translationOverrides';
import { translationProposalsApi, type TranslationProposal } from '@/api/translationProposals';
import { useAuth } from '@/features/auth/useAuth';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Modal } from '@/components/ui/Modal';
import { PageHeader } from '@/components/ui/PageHeader';
import { SearchInput } from '@/components/ui/SearchInput';
import { TableScroll } from '@/components/ui/TableScroll';
import { useToast } from '@/components/ui/useToast';
import { TRANSLATION_OVERRIDES_QUERY_KEY } from '@/hooks/useTranslationOverrides';
import { placeholdersOf } from '@/i18n/catalog';
import { CATALOGUE } from '@/i18n/messages';
import { effectiveCatalogue, getOverrides, isLockedEntry, keepsPlaceholders, setOverrides } from '@/i18n/overrides';
import { useI18n } from '@/i18n/useI18n';

const MAX_TEXT_LENGTH = 1000;
const MAX_ROWS = 100;
const KEYS = Object.keys(CATALOGUE).sort();

/**
 * Super Admin edits the English and Somali wording of any message the panel shows. The built-in text stays in the code; an edit is saved on the
 * server as an override of that one message and laid over the built-in text for everyone. "Reset" removes the override.
 *
 * Owner decision TRANSLATION-POLICY (2026-11-06): the HR Manager and the Marketing Manager see the same list but can only PROPOSE a change; a proposal
 * never changes the wording in use. Only the Super Admin approves (or rejects) the proposals, in the list under the table. The server enforces all of it.
 */
export function TranslationsPage() {
  const { t, language } = useI18n();
  const { show } = useToast();
  const queryClient = useQueryClient();
  const { isSuperAdmin } = useAuth();
  const [search, setSearch] = useState('');
  const [onlyEdited, setOnlyEdited] = useState(false);
  const [editingKey, setEditingKey] = useState<string | null>(null);
  const [proposingKey, setProposingKey] = useState<string | null>(null);

  const overridesQuery = useQuery({ queryKey: TRANSLATION_OVERRIDES_QUERY_KEY, queryFn: () => translationOverridesApi.list(), staleTime: 60_000 });
  const overrides = overridesQuery.data ?? getOverrides();
  const effective = effectiveCatalogue(CATALOGUE);

  const rows = useMemo(() => {
    const needle = search.trim().toLowerCase();

    return KEYS.filter((key) => {
      if (onlyEdited && !overrides[key]) return false;
      if (needle === '') return true;
      const built = CATALOGUE[key as keyof typeof CATALOGUE];
      const shown = effective[key];

      return [key, built.en, built.so, shown.en, shown.so].some((text) => text.toLowerCase().includes(needle));
    });
    // `language` is not read inside, but a change of language or of the overrides must refresh what the list shows.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, onlyEdited, overrides, effective, language]);

  async function refreshAfterChange() {
    const next = await queryClient.fetchQuery({ queryKey: TRANSLATION_OVERRIDES_QUERY_KEY, queryFn: () => translationOverridesApi.list(), staleTime: 0 });
    setOverrides(next);
  }

  const resetMutation = useMutation({
    mutationFn: (key: string) => translationOverridesApi.reset(key),
    onSuccess: async () => {
      await refreshAfterChange();
      show(t('notify.system.translationReset'));
      setEditingKey(null);
    },
    onError: () => show(t('notify.system.translationResetFailed'), 'error'),
  });

  return (
    <div>
      <PageHeader
        title={t('option.system.pageTranslations')}
        breadcrumb={[{ label: t('option.system.system') }, { label: t('option.system.pageTranslations') }]}
      />

      <Card className="p-4">
        <p className="mb-4 text-sm text-ink-muted">{isSuperAdmin ? t('warning.system.translationsIntro') : t('warning.ownerDecisions1c.translationsProposeIntro')}</p>

        <div className="mb-4 flex flex-wrap items-center gap-3">
          <SearchInput className="w-full max-w-md" value={search} onChange={setSearch} placeholder={t('option.system.translationsSearchPlaceholder')} />
          <label className="flex items-center gap-2 text-sm text-ink">
            <input type="checkbox" checked={onlyEdited} onChange={(event) => setOnlyEdited(event.target.checked)} />
            {t('option.system.translationsOnlyEdited')}
          </label>
        </div>

        {rows.length === 0 ? (
          <p className="py-8 text-center text-sm text-ink-muted">{t('warning.system.translationsNone')}</p>
        ) : (
          <TableScroll>
            <table className="w-full min-w-[720px] border-collapse text-left text-sm">
              <thead>
                <tr className="border-b border-border bg-surface-alt">
                  <th className="px-3 py-2 text-xs font-semibold uppercase text-ink-muted">{t('option.system.translationsColKey')}</th>
                  <th className="px-3 py-2 text-xs font-semibold uppercase text-ink-muted">{t('option.system.translationsColEnglish')}</th>
                  <th className="px-3 py-2 text-xs font-semibold uppercase text-ink-muted">{t('option.system.translationsColSomali')}</th>
                  <th className="px-3 py-2" />
                </tr>
              </thead>
              <tbody>
                {rows.slice(0, MAX_ROWS).map((key) => (
                  <tr key={key} className="border-b border-border align-top">
                    <td className="px-3 py-2 font-mono text-xs text-ink-muted">
                      {key}
                      {overrides[key] && (
                        <span className="ml-2 rounded-full bg-primary-soft px-2 py-0.5 font-sans text-[10px] font-semibold text-primary">
                          {t('option.system.translationEdited')}
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-2 text-ink">{effective[key].en}</td>
                    <td className="px-3 py-2 text-ink">{effective[key].so}</td>
                    <td className="px-3 py-2 text-right">
                      {isLockedMessage(key) ? (
                        // Owner-approved wording is locked: nobody edits, proposes or approves a change to it here (the server refuses it too).
                        <span className="rounded-full bg-surface-alt px-2 py-0.5 text-[10px] font-semibold text-ink-muted">{t('option.ownerDecisions1c.translationLocked')}</span>
                      ) : (
                        <Button variant="outline" size="sm" onClick={() => (isSuperAdmin ? setEditingKey(key) : setProposingKey(key))}>
                          {isSuperAdmin ? t('action.edit') : t('action.ownerDecisions1c.proposeChange')}
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableScroll>
        )}

        {rows.length > MAX_ROWS && (
          <p className="mt-3 text-xs text-ink-faint">{t('warning.system.translationsShowing', { shown: MAX_ROWS, total: rows.length })}</p>
        )}
      </Card>

      <ProposalsCard isSuperAdmin={isSuperAdmin} onApproved={refreshAfterChange} />

      {!isSuperAdmin && proposingKey && <ProposeWordingDialog key={proposingKey} messageKey={proposingKey} onClose={() => setProposingKey(null)} />}

      {isSuperAdmin && editingKey && (
        <EditWordingDialog
          key={editingKey}
          messageKey={editingKey}
          isEdited={Boolean(overrides[editingKey])}
          onClose={() => setEditingKey(null)}
          onSaved={async () => {
            await refreshAfterChange();
            show(t('notify.system.translationSaved'));
            setEditingKey(null);
          }}
          onReset={() => resetMutation.mutate(editingKey)}
          isResetting={resetMutation.isPending}
        />
      )}
    </div>
  );
}

interface EditWordingDialogProps {
  messageKey: string;
  isEdited: boolean;
  onClose: () => void;
  onSaved: () => Promise<void>;
  onReset: () => void;
  isResetting: boolean;
}

function EditWordingDialog({ messageKey, isEdited, onClose, onSaved, onReset, isResetting }: EditWordingDialogProps) {
  const { t } = useI18n();
  const { show } = useToast();
  const builtIn = CATALOGUE[messageKey as keyof typeof CATALOGUE];
  const shown = effectiveCatalogue(CATALOGUE)[messageKey];
  const [en, setEn] = useState(shown.en);
  const [so, setSo] = useState(shown.so);
  const [errors, setErrors] = useState<{ en?: string; so?: string; form?: string }>({});
  const placeholders = placeholdersOf(builtIn.en);
  const placeholderList = placeholders.map((name) => `{${name}}`).join(', ');

  const saveMutation = useMutation({
    mutationFn: (wording: { en: string; so: string }) => translationOverridesApi.save(messageKey, wording),
    onSuccess: onSaved,
    onError: (error) => {
      if (error instanceof ApiClientError && error.isValidation) {
        const fieldErrors = error.fieldErrors ?? {};
        setErrors({ en: fieldErrors.en?.[0], so: fieldErrors.so?.[0], form: fieldErrors.en || fieldErrors.so ? undefined : error.message });
      } else {
        show(t('notify.system.translationFailed'), 'error');
      }
    },
  });

  function check(text: string): string | undefined {
    if (text.length > MAX_TEXT_LENGTH) return t('validation.system.translationTooLong', { max: MAX_TEXT_LENGTH });
    if (text.trim() !== '' && !keepsPlaceholders(builtIn.en, text)) return t('validation.system.translationPlaceholders', { list: placeholderList || '-' });

    return undefined;
  }

  function handleSubmit(event?: FormEvent) {
    event?.preventDefault();
    const next = { en: check(en), so: check(so) };
    if (next.en || next.so) {
      setErrors(next);
      return;
    }
    // Text identical to the built-in wording is not stored as an edit.
    const wording = { en: en.trim() === builtIn.en ? '' : en.trim(), so: so.trim() === builtIn.so ? '' : so.trim() };
    if (wording.en === '' && wording.so === '') {
      if (isEdited) onReset();
      else onClose();
      return;
    }
    setErrors({});
    saveMutation.mutate(wording);
  }

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('option.system.translationEditTitle')}
      size="lg"
      confirmDiscard
      footer={
        <>
          {isEdited && (
            <Button variant="outline" size="sm" onClick={onReset} isLoading={isResetting} disabled={saveMutation.isPending}>
              {t('action.system.translationReset')}
            </Button>
          )}
          <Button variant="outline" size="sm" onClick={onClose} disabled={saveMutation.isPending}>
            {t('action.cancel')}
          </Button>
          <Button size="sm" onClick={() => handleSubmit()} isLoading={saveMutation.isPending}>
            {t('action.save')}
          </Button>
        </>
      }
    >
      <form onSubmit={handleSubmit}>
        <p className="mb-1 font-mono text-xs text-ink-muted">{messageKey}</p>
        {placeholders.length > 0 && <p className="mb-3 text-xs text-ink-muted">{t('warning.system.translationPlaceholders', { list: placeholderList })}</p>}
        <FormField label={t('option.system.translationEnglishField')} htmlFor="wording-en" error={errors.en} hint={t('option.system.translationBuiltIn', { text: builtIn.en })}>
          <textarea id="wording-en" rows={3} className={`${inputClasses} h-auto py-2`} value={en} onChange={(event) => setEn(event.target.value)} />
        </FormField>
        <FormField label={t('option.system.translationSomaliField')} htmlFor="wording-so" error={errors.so} hint={t('option.system.translationBuiltIn', { text: builtIn.so })}>
          <textarea id="wording-so" rows={3} className={`${inputClasses} h-auto py-2`} value={so} onChange={(event) => setSo(event.target.value)} />
        </FormField>
        {errors.form && <p className="text-xs text-danger">{errors.form}</p>}
      </form>
    </Modal>
  );
}

const PROPOSAL_STATUS_KEY = {
  pending: 'status.ownerDecisions1c.proposalPending',
  approved: 'status.ownerDecisions1c.proposalApproved',
  rejected: 'status.ownerDecisions1c.proposalRejected',
  withdrawn: 'status.ownerDecisions1c.proposalWithdrawn',
} as const;

const PROPOSALS_QUERY_KEY = ['translation-proposals'] as const;

/** True when the wording of this message is one the owner approved: it is LOCKED (no edit, no proposal, no approval, no override changes what is shown). */
function isLockedMessage(messageKey: string): boolean {
  const entry = (CATALOGUE as Record<string, Parameters<typeof isLockedEntry>[0]>)[messageKey];

  return entry !== undefined && isLockedEntry(entry);
}

/**
 * The proposals: a proposer sees their own (and can withdraw a pending one); the Super Admin sees all of them and approves or rejects the pending ones.
 * Approving writes the override the editor writes, so the new wording is in use straight away; nothing else changes it.
 */
function ProposalsCard({ isSuperAdmin, onApproved }: { isSuperAdmin: boolean; onApproved: () => Promise<void> }) {
  const { t } = useI18n();
  const { show } = useToast();
  const queryClient = useQueryClient();
  const query = useQuery({ queryKey: PROPOSALS_QUERY_KEY, queryFn: () => translationProposalsApi.list() });
  const [rejecting, setRejecting] = useState<number | null>(null);
  const [note, setNote] = useState('');
  const effective = effectiveCatalogue(CATALOGUE);
  const proposals = query.data ?? [];

  const refresh = () => queryClient.invalidateQueries({ queryKey: PROPOSALS_QUERY_KEY });
  const failed = () => show(t('notify.ownerDecisions1c.proposalActionFailed'), 'error');

  const approve = useMutation({
    mutationFn: (id: number) => translationProposalsApi.approve(id),
    onSuccess: async () => {
      await refresh();
      await onApproved();
      show(t('notify.ownerDecisions1c.proposalApproved'));
    },
    onError: failed,
  });
  const reject = useMutation({
    mutationFn: (id: number) => translationProposalsApi.reject(id, note.trim()),
    onSuccess: async () => {
      await refresh();
      setRejecting(null);
      setNote('');
      show(t('notify.ownerDecisions1c.proposalRejected'));
    },
    onError: failed,
  });
  const withdraw = useMutation({
    mutationFn: (id: number) => translationProposalsApi.withdraw(id),
    onSuccess: async () => {
      await refresh();
      show(t('notify.ownerDecisions1c.proposalWithdrawn'));
    },
    onError: failed,
  });

  function wording(label: string, en: string | null | undefined, so: string | null | undefined) {
    return (
      <div className="min-w-0 flex-1 rounded-sm bg-surface-alt px-2 py-1.5 text-xs text-ink">
        <p className="mb-0.5 font-medium text-ink-faint">{label}</p>
        {en != null && <p><span className="text-ink-faint">{t('option.system.translationEnglishField')}: </span>{en}</p>}
        {so != null && <p><span className="text-ink-faint">{t('option.system.translationSomaliField')}: </span>{so}</p>}
      </div>
    );
  }

  return (
    <Card className="mt-4 p-4">
      <h3 className="mb-3 font-display text-sm font-bold text-ink">{isSuperAdmin ? t('option.ownerDecisions1c.proposalsAll') : t('option.ownerDecisions1c.proposalsMine')}</h3>
      {proposals.length === 0 ? (
        <p className="py-4 text-center text-sm text-ink-muted">{t('warning.ownerDecisions1c.proposalsNone')}</p>
      ) : (
        <ul className="space-y-3">
          {proposals.map((p: TranslationProposal) => {
            const current = effective[p.message_key];

            return (
              <li key={p.id} className="rounded-sm border border-border p-3">
                <div className="mb-2 flex flex-wrap items-center gap-2">
                  <span className="font-mono text-xs text-ink-muted">{p.message_key}</span>
                  <span className="rounded-full bg-primary-soft px-2 py-0.5 text-[10px] font-semibold text-primary">{t(PROPOSAL_STATUS_KEY[p.status])}</span>
                  <span className="text-xs text-ink-faint">{t('option.ownerDecisions1c.proposedBy', { name: p.proposed_by.name ?? '—' })}</span>
                </div>
                <div className="flex flex-col gap-2 sm:flex-row">
                  {wording(t('option.ownerDecisions1c.wordingInUse'), current?.en, current?.so)}
                  {wording(t('option.ownerDecisions1c.proposedWording'), p.en, p.so)}
                </div>
                {p.reason && <p className="mt-2 text-xs text-ink-muted">{p.reason}</p>}
                {p.decision_note && <p className="mt-1 text-xs text-ink-muted">{p.decision_note}</p>}

                {p.status === 'pending' && (
                  <div className="mt-2 flex flex-wrap items-center gap-2">
                    {isSuperAdmin ? (
                      <>
                        <Button size="sm" isLoading={approve.isPending && approve.variables === p.id} disabled={reject.isPending} onClick={() => approve.mutate(p.id)}>
                          {t('action.ownerDecisions1c.approveProposal')}
                        </Button>
                        <Button size="sm" variant="outline" disabled={approve.isPending} onClick={() => { setRejecting(rejecting === p.id ? null : p.id); setNote(''); }}>
                          {t('action.ownerDecisions1c.rejectProposal')}
                        </Button>
                      </>
                    ) : (
                      <Button size="sm" variant="outline" isLoading={withdraw.isPending && withdraw.variables === p.id} onClick={() => withdraw.mutate(p.id)}>
                        {t('action.ownerDecisions1c.withdrawProposal')}
                      </Button>
                    )}
                  </div>
                )}

                {isSuperAdmin && rejecting === p.id && (
                  <div className="mt-2 flex items-start gap-2">
                    <textarea
                      rows={2}
                      maxLength={500}
                      placeholder={t('option.ownerDecisions1c.rejectNote')}
                      value={note}
                      onChange={(event) => setNote(event.target.value)}
                      className={`${inputClasses} h-auto min-w-0 flex-1 py-1.5 text-xs`}
                    />
                    <Button size="sm" isLoading={reject.isPending} onClick={() => reject.mutate(p.id)}>
                      {t('action.ownerDecisions1c.rejectProposal')}
                    </Button>
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </Card>
  );
}

/** HR Manager / Marketing Manager: propose new wording for one message. Nothing changes in use until a Super Admin approves it. */
function ProposeWordingDialog({ messageKey, onClose }: { messageKey: string; onClose: () => void }) {
  const { t } = useI18n();
  const { show } = useToast();
  const queryClient = useQueryClient();
  const builtIn = CATALOGUE[messageKey as keyof typeof CATALOGUE];
  const shown = effectiveCatalogue(CATALOGUE)[messageKey];
  const [en, setEn] = useState(shown.en);
  const [so, setSo] = useState(shown.so);
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<{ en?: string; so?: string; form?: string }>({});
  const placeholders = placeholdersOf(builtIn.en);
  const placeholderList = placeholders.map((name) => `{${name}}`).join(', ');

  const mutation = useMutation({
    mutationFn: (body: { en: string; so: string }) => translationProposalsApi.propose({ key: messageKey, ...body, reason: reason.trim() }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: PROPOSALS_QUERY_KEY });
      show(t('notify.ownerDecisions1c.proposalSent'));
      onClose();
    },
    onError: (error) => {
      if (error instanceof ApiClientError && error.code === 'PROPOSAL_ALREADY_PENDING') {
        setErrors({ form: t('notify.ownerDecisions1c.proposalAlreadyPending') });
      } else if (error instanceof ApiClientError && error.isValidation) {
        const fieldErrors = error.fieldErrors ?? {};
        setErrors({ en: fieldErrors.en?.[0], so: fieldErrors.so?.[0], form: fieldErrors.en || fieldErrors.so ? undefined : error.message });
      } else {
        show(t('notify.ownerDecisions1c.proposalFailed'), 'error');
      }
    },
  });

  function check(text: string): string | undefined {
    if (text.length > MAX_TEXT_LENGTH) return t('validation.system.translationTooLong', { max: MAX_TEXT_LENGTH });
    if (text.trim() !== '' && !keepsPlaceholders(builtIn.en, text)) return t('validation.system.translationPlaceholders', { list: placeholderList || '-' });

    return undefined;
  }

  function handleSubmit(event?: FormEvent) {
    event?.preventDefault();
    const next = { en: check(en), so: check(so) };
    if (next.en || next.so) {
      setErrors(next);
      return;
    }
    // Only a language whose text differs from the wording in use is proposed.
    const body = { en: en.trim() === shown.en ? '' : en.trim(), so: so.trim() === shown.so ? '' : so.trim() };
    if (body.en === '' && body.so === '') {
      setErrors({ form: t('validation.ownerDecisions1c.proposalNoChange') });
      return;
    }
    setErrors({});
    mutation.mutate(body);
  }

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('option.ownerDecisions1c.proposeTitle')}
      size="lg"
      confirmDiscard
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>
            {t('action.cancel')}
          </Button>
          <Button size="sm" onClick={() => handleSubmit()} isLoading={mutation.isPending}>
            {t('action.ownerDecisions1c.sendProposal')}
          </Button>
        </>
      }
    >
      <form onSubmit={handleSubmit}>
        <p className="mb-1 font-mono text-xs text-ink-muted">{messageKey}</p>
        {placeholders.length > 0 && <p className="mb-3 text-xs text-ink-muted">{t('warning.system.translationPlaceholders', { list: placeholderList })}</p>}
        <FormField label={t('option.system.translationEnglishField')} htmlFor="proposal-en" error={errors.en}>
          <textarea id="proposal-en" rows={3} className={`${inputClasses} h-auto py-2`} value={en} onChange={(event) => setEn(event.target.value)} />
        </FormField>
        <FormField label={t('option.system.translationSomaliField')} htmlFor="proposal-so" error={errors.so}>
          <textarea id="proposal-so" rows={3} className={`${inputClasses} h-auto py-2`} value={so} onChange={(event) => setSo(event.target.value)} />
        </FormField>
        <FormField label={t('option.ownerDecisions1c.proposalReason')} htmlFor="proposal-reason">
          <textarea id="proposal-reason" rows={2} maxLength={500} className={`${inputClasses} h-auto py-2`} value={reason} onChange={(event) => setReason(event.target.value)} />
        </FormField>
        {errors.form && <p className="text-xs text-danger">{errors.form}</p>}
      </form>
    </Modal>
  );
}
