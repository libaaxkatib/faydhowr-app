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
import { recordFieldLabel, type PaymentListOption, type PaymentListScope } from '@/utils/paymentListFilter';

interface PaymentListPreviewDialogProps {
  isOpen: boolean;
  period: string;
  /** The month as the screen words it ("October 2026" / "Oktoobar 2026"). */
  monthLabel: string;
  scope: PaymentListScope;
  selected: PaymentListOption | null;
  /** True while the Excel is being downloaded: the dialog cannot be closed and the button cannot be pressed twice. */
  isDownloading: boolean;
  onClose: () => void;
  onConfirm: () => void;
}

function amount(value: string): string {
  const number = Number(value);

  return Number.isFinite(number) ? formatCurrency(number) : value;
}

/**
 * The check shown before the payment list is downloaded for ONE company or guri: the record, the month, the row count, the Permanent / Temporary /
 * combined totals and how many rows have no payment number. Every figure comes from the server's read-only preview, which is built from the same
 * report as the Excel file, so what is shown here is what the file will hold. Nothing is stored or audited until the person confirms the download.
 */
export function PaymentListPreviewDialog({ isOpen, period, monthLabel, scope, selected, isDownloading, onClose, onConfirm }: PaymentListPreviewDialogProps) {
  const { t } = useI18n();

  const { data, error, isLoading, isFetching, refetch } = useQuery({
    queryKey: ['payment-list-preview', period, scope, selected?.id ?? null],
    queryFn: () => hrApi.payrollRuns.paymentListPreview(period, scope, selected),
    enabled: isOpen && selected !== null,
    // A preview is only worth showing if it is current: it is fetched again each time the dialog opens and is never kept.
    gcTime: 0,
    staleTime: 0,
    retry: false,
  });

  const nothingToPay = error instanceof ApiClientError && error.code === 'NO_PAYMENT_RECORDS';
  const canConfirm = data !== undefined && !error && !isFetching && !isDownloading;

  return (
    <Modal
      isOpen={isOpen}
      onClose={isDownloading ? () => undefined : onClose}
      title={t('confirm.payroll.listPreviewTitle')}
      size="md"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={isDownloading}>
            {t('action.cancel')}
          </Button>
          <Button size="sm" onClick={onConfirm} isLoading={isDownloading} disabled={!canConfirm}>
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

      {data && (
        <div data-testid="payment-list-preview">
          <dl className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-2 text-sm">
            <dt className="text-ink-muted">{recordFieldLabel(scope)}</dt>
            <dd className="text-right font-medium text-ink">{data.record?.name ?? selected?.name}</dd>

            <dt className="text-ink-muted">{t('option.payroll.previewMonth')}</dt>
            <dd className="text-right font-medium text-ink">{monthLabel}</dd>

            <dt className="text-ink-muted">{t('option.payroll.previewRows')}</dt>
            <dd className="text-right font-medium text-ink" data-testid="preview-rows">{data.row_count}</dd>

            <dt className="text-ink-muted">{t('option.payroll.previewPermanentTotal')}</dt>
            <dd className="text-right font-medium text-ink" data-testid="preview-permanent">{amount(data.permanent_total)}</dd>

            <dt className="text-ink-muted">{t('option.payroll.previewTemporaryTotal')}</dt>
            <dd className="text-right font-medium text-ink" data-testid="preview-temporary">{amount(data.temporary_total)}</dd>

            <div role="presentation" className="col-span-2 border-t border-border" />
            <dt className="font-semibold text-ink">{t('option.payroll.previewCombinedTotal')}</dt>
            <dd className="text-right font-semibold text-ink" data-testid="preview-combined">{amount(data.combined_total)}</dd>

            <dt className="text-ink-muted">{t('option.payroll.previewMissingNumbers')}</dt>
            <dd className="text-right font-medium text-ink" data-testid="preview-missing">{data.missing_payment_number_count}</dd>
          </dl>

          {data.missing_payment_number_count > 0 && (
            <p role="status" className="mt-4 rounded-sm bg-warning-soft px-3 py-2 text-sm text-ink">
              {t('warning.payroll.previewMissingNumbers')}
            </p>
          )}
          {data.is_estimate && (
            <p role="status" className="mt-2 rounded-sm bg-warning-soft px-3 py-2 text-sm text-ink">
              {t('warning.payroll.previewEstimate')}
            </p>
          )}
          <p className="mt-4 text-xs text-ink-muted">{t('warning.payroll.previewOnly')}</p>
        </div>
      )}
    </Modal>
  );
}
