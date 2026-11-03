import { useEffect, useState, type KeyboardEvent } from 'react';
import { useQuery } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { ApiClientError } from '@/api/client';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { LoadingState } from '@/components/ui/LoadingState';
import { Modal } from '@/components/ui/Modal';
import { useI18n } from '@/i18n/useI18n';
import { formatCurrency } from '@/utils/formatters';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import { cellClasses, recordFieldLabel, type PaymentListOption, type PaymentListScope, type PreviewSheet } from '@/utils/paymentListFilter';

interface PaymentListPreviewDialogProps {
  isOpen: boolean;
  period: string;
  /** The month as the screen words it ("October 2026" / "Oktoobar 2026"). */
  monthLabel: string;
  scope: PaymentListScope;
  /** The chosen company or guri; null for the all-records list. */
  selected: PaymentListOption | null;
  /** True while the Excel is being downloaded: the dialog cannot be closed and the button cannot be pressed twice. */
  isDownloading: boolean;
  /** True when the last download was refused because the list changed after this preview was shown. */
  changedAfterPreview: boolean;
  onClose: () => void;
  /** The download is asked for THIS preview (its hash and stamp): the file must hold exactly what is shown. */
  onConfirm: (previewed: { content_hash: string; generated_at: string }) => void;
}

function amount(value: string): string {
  const number = Number(value);

  return Number.isFinite(number) ? formatCurrency(number) : value;
}

/**
 * The check shown before ANY payment-list Excel is downloaded (all records, one company or one guri): the totals, then every worksheet of the
 * workbook with its columns, rows, values, totals and highlights, one tab per worksheet. All of it is read back from the file the server would send, so
 * what is shown here is what the file holds; the download then names this preview and the server refuses a file that differs from it. Nothing is
 * stored or audited until the person confirms the download.
 */
export function PaymentListPreviewDialog({ isOpen, period, monthLabel, scope, selected, isDownloading, changedAfterPreview, onClose, onConfirm }: PaymentListPreviewDialogProps) {
  const { t } = useI18n();
  const [sheetIndex, setSheetIndex] = useState(0);

  const { data, error, isLoading, isFetching, refetch } = useQuery({
    queryKey: ['payment-list-preview', period, scope, selected?.id ?? null],
    queryFn: () => hrApi.payrollRuns.paymentListWorkbookPreview(period, scope, selected),
    enabled: isOpen && (scope === 'all' || selected !== null),
    // A preview is only worth showing if it is current: it is fetched again each time the dialog opens and is never kept.
    gcTime: 0,
    staleTime: 0,
    retry: false,
  });

  // A new opening, or a new list after a change, starts on the first worksheet.
  useEffect(() => {
    setSheetIndex(0);
  }, [isOpen, period, scope, selected?.id, data?.content_hash]);

  const nothingToPay = error instanceof ApiClientError && error.code === 'NO_PAYMENT_RECORDS';
  const canConfirm = data !== undefined && !error && !isFetching && !isDownloading;
  const sheet = data?.sheets[Math.min(sheetIndex, (data?.sheets.length ?? 1) - 1)];
  const recordName = data?.record?.name ?? selected?.name ?? t('option.payroll.scopeAll');

  return (
    <Modal
      isOpen={isOpen}
      onClose={isDownloading ? () => undefined : onClose}
      title={t('confirm.payroll.listPreviewTitle')}
      size="xl"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={isDownloading}>
            {t('action.cancel')}
          </Button>
          <Button size="sm" onClick={() => data && onConfirm({ content_hash: data.content_hash, generated_at: data.generated_at })} isLoading={isDownloading} disabled={!canConfirm}>
            <Icon name="file-text" size={14} />
            {t('action.payroll.downloadExcel')}
          </Button>
        </>
      }
    >
      {isLoading && <LoadingState label={t('option.payroll.loadingPreview')} />}

      {nothingToPay && selected && (
        <p role="alert" data-testid="payment-list-preview-empty" className="rounded-sm bg-warning-soft px-3 py-2 text-sm text-ink">
          {t('warning.payroll.previewNoRecords', { name: selected.name, month: monthLabel })}
        </p>
      )}

      {error && !nothingToPay && (
        <div role="alert" data-testid="payment-list-preview-error" className="rounded-sm bg-danger-soft px-3 py-3 text-sm text-ink">
          <p>{friendlyErrorMessage(error, t('notify.payroll.previewFailed'))}</p>
          <Button variant="outline" size="sm" className="mt-3" onClick={() => void refetch()} disabled={isFetching}>
            <Icon name="refresh" size={14} />
            {t('action.common.tryAgain')}
          </Button>
        </div>
      )}

      {changedAfterPreview && (
        <p role="alert" data-testid="payment-list-preview-changed" className="mb-3 rounded-sm bg-warning-soft px-3 py-2 text-sm text-ink">
          {t('warning.payroll.previewChanged')}
        </p>
      )}

      {data && (
        <div data-testid="payment-list-preview">
          <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm md:grid-cols-4">
            <div>
              <dt className="text-ink-muted">{scope === 'all' ? t('option.payroll.scopeAll') : recordFieldLabel(scope)}</dt>
              <dd className="font-medium text-ink" data-testid="preview-record">{recordName}</dd>
            </div>
            <div>
              <dt className="text-ink-muted">{t('option.payroll.previewMonth')}</dt>
              <dd className="font-medium text-ink">{monthLabel}</dd>
            </div>
            <div>
              <dt className="text-ink-muted">{t('option.payroll.previewRows')}</dt>
              <dd className="font-medium text-ink" data-testid="preview-rows">{data.row_count}</dd>
            </div>
            <div>
              <dt className="text-ink-muted">{t('option.payroll.previewMissingNumbers')}</dt>
              <dd className="font-medium text-ink" data-testid="preview-missing">{data.missing_payment_number_count}</dd>
            </div>
            <div>
              <dt className="text-ink-muted">{t('option.payroll.previewPermanentTotal')}</dt>
              <dd className="font-medium text-ink" data-testid="preview-permanent">{amount(data.permanent_total)}</dd>
            </div>
            <div>
              <dt className="text-ink-muted">{t('option.payroll.previewTemporaryTotal')}</dt>
              <dd className="font-medium text-ink" data-testid="preview-temporary">{amount(data.temporary_total)}</dd>
            </div>
            <div>
              <dt className="font-semibold text-ink">{t('option.payroll.previewCombinedTotal')}</dt>
              <dd className="font-semibold text-ink" data-testid="preview-combined">{amount(data.combined_total)}</dd>
            </div>
          </dl>

          {data.missing_payment_number_count > 0 && (
            <p role="status" className="mt-3 rounded-sm bg-warning-soft px-3 py-2 text-sm text-ink">
              {t('warning.payroll.previewMissingNumbers')}
            </p>
          )}
          {data.is_estimate && (
            <p role="status" className="mt-2 rounded-sm bg-warning-soft px-3 py-2 text-sm text-ink">
              {t('warning.payroll.previewEstimate')}
            </p>
          )}

          <WorksheetTabs sheets={data.sheets} index={sheetIndex} onChange={setSheetIndex} label={t('option.payroll.previewSheets')} />
          {sheet && <SheetTable sheet={sheet} note={t('option.payroll.previewFreezeNote')} size={t('option.payroll.previewSheetSize', { rows: sheet.row_count, columns: sheet.column_count })} />}

          <p className="mt-3 text-xs text-ink-muted">{t('warning.payroll.previewOnly')}</p>
        </div>
      )}
    </Modal>
  );
}

/** One tab per worksheet of the workbook, in the workbook's order; the arrow keys move between them. */
function WorksheetTabs({ sheets, index, onChange, label }: { sheets: PreviewSheet[]; index: number; onChange: (index: number) => void; label: string }) {
  const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
    if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
    event.preventDefault();
    onChange((index + (event.key === 'ArrowRight' ? 1 : sheets.length - 1)) % sheets.length);
  };

  return (
    <div role="tablist" aria-label={label} className="mt-4 flex flex-wrap gap-1 border-b border-border">
      {sheets.map((sheet, position) => (
        <button
          key={sheet.name}
          type="button"
          role="tab"
          aria-selected={position === index}
          tabIndex={position === index ? 0 : -1}
          data-testid={`preview-tab-${position}`}
          onClick={() => onChange(position)}
          onKeyDown={onKeyDown}
          className={`-mb-px rounded-t-sm border border-b-0 px-3 py-1.5 text-sm ${position === index ? 'border-border bg-surface font-semibold text-ink' : 'border-transparent text-ink-muted hover:text-ink'}`}
        >
          {sheet.name}
        </button>
      ))}
    </div>
  );
}

/** True for a row that holds only its first cell (trailing empty cells are not sent): a title, a note or a section heading. */
function spansWholeRow(row: PreviewSheet['rows'][number], columnCount: number): boolean {
  return columnCount > 1 && row.length === 1 && row[0] !== null;
}

/** A worksheet as a table: every row and column as the workbook has them, the table's header row kept in view, the workbook's highlights as theme colours. */
function SheetTable({ sheet, note, size }: { sheet: PreviewSheet; note: string; size: string }) {
  const widths = Array.from({ length: sheet.column_count }, (_, column) => Math.max(8, Math.round(sheet.column_widths[column] ?? 10)));
  const total = widths.reduce((sum, width) => sum + width, 0);

  return (
    <div role="tabpanel" data-testid="preview-sheet" data-sheet={sheet.name}>
      <p className="my-2 text-xs text-ink-muted">
        <span data-testid="preview-sheet-size">{size}</span>
        {sheet.header_row !== null && ` · ${note}`}
      </p>
      <div className="max-h-[50vh] overflow-auto rounded-sm border border-border">
        <table className="border-collapse text-xs text-ink" style={{ tableLayout: 'fixed', width: `calc(${total}ch + 3rem)` }}>
          <colgroup>
            <col style={{ width: '3rem' }} />
            {widths.map((width, column) => (
              <col key={column} style={{ width: `${width}ch` }} />
            ))}
          </colgroup>
          <tbody>
            {sheet.rows.map((row, rowIndex) => {
              const isHeader = sheet.header_row === rowIndex + 1;

              return (
                <tr key={rowIndex} data-testid={isHeader ? 'preview-header-row' : undefined}>
                  <th scope="row" className="sticky left-0 border border-border bg-surface-muted px-1 py-0.5 text-right font-normal text-ink-faint">{rowIndex + 1}</th>
                  {/* A row that only has its first cell (a title, a note, a section heading) runs across the whole width, as Excel shows it. */}
                  {spansWholeRow(row, sheet.column_count) && row[0] ? (
                    <td colSpan={sheet.column_count} className={`whitespace-pre-wrap break-words border border-border px-2 py-0.5 align-top ${cellClasses(sheet.styles[row[0][1]])}`}>
                      {row[0][0]}
                    </td>
                  ) : (
                    Array.from({ length: sheet.column_count }, (_, column) => {
                      const cell = row[column] ?? null;

                      return (
                        <td
                          key={column}
                          className={`whitespace-pre-wrap break-words border border-border px-2 py-0.5 align-top ${cellClasses(cell ? sheet.styles[cell[1]] : undefined)} ${isHeader ? 'sticky top-0 z-10' : ''}`}
                        >
                          {cell?.[0] ?? ''}
                        </td>
                      );
                    })
                  )}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
