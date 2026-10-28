import { useMemo, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ApiClientError } from '@/api/client';
import { translationOverridesApi } from '@/api/translationOverrides';
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
import { effectiveCatalogue, getOverrides, keepsPlaceholders, setOverrides } from '@/i18n/overrides';
import { useI18n } from '@/i18n/useI18n';

const MAX_TEXT_LENGTH = 1000;
const MAX_ROWS = 100;
const KEYS = Object.keys(CATALOGUE).sort();

/**
 * Super Admin edits the English and Somali wording of any message the panel shows. The built-in text stays in the code; an edit is saved on the
 * server as an override of that one message and laid over the built-in text for everyone. "Reset" removes the override.
 */
export function TranslationsPage() {
  const { t, language } = useI18n();
  const { show } = useToast();
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [onlyEdited, setOnlyEdited] = useState(false);
  const [editingKey, setEditingKey] = useState<string | null>(null);

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
        <p className="mb-4 text-sm text-ink-muted">{t('warning.system.translationsIntro')}</p>

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
                      <Button variant="outline" size="sm" onClick={() => setEditingKey(key)}>
                        {t('action.edit')}
                      </Button>
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

      {editingKey && (
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
