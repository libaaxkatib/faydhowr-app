import { Fragment, type ReactNode } from 'react';

import type { MessageKey } from '@/i18n/messages';
import { useI18n } from '@/i18n/useI18n';

/**
 * A translated message whose `{placeholders}` are filled with React elements instead of plain text, for sentences that carry inline emphasis or a
 * link, for example `<Msg id="confirm.employees.restoreBody" values={{ name: <strong>{employee.full_name}</strong> }} />` for
 * "Restore {name} to the working lists?". Placeholders with no value are left as written. Plain-text messages should use `t()` instead.
 */
export function Msg({ id, values }: { id: MessageKey; values?: Record<string, ReactNode> }) {
  const { t } = useI18n();
  const parts = t(id).split(/(\{[A-Za-z0-9_]+\})/g);

  return (
    <>
      {parts.map((part, index) => {
        if (part === '') return null;
        const name = /^\{([A-Za-z0-9_]+)\}$/.exec(part)?.[1];

        return <Fragment key={index}>{name !== undefined && values && name in values ? values[name] : part}</Fragment>;
      })}
    </>
  );
}
