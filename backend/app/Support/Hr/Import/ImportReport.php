<?php

namespace App\Support\Hr\Import;

use App\Models\ImportBatch;
use App\Models\ImportRow;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Row-level CSV report for a batch: one line per workbook row with its status, code and a plain-language
 * message. It deliberately contains NO names or phone numbers, only the sheet row number, so it can be
 * shared and still be matched to the original sheet. Activation previews add the per-employee path
 * columns stored in the row's meta (employee number and statuses only) while that data is retained.
 */
final class ImportReport
{
    private const BASE = ['row', 'status', 'code', 'message', 'record_type', 'record_id'];

    public function response(ImportBatch $batch, ?string $status = null): StreamedResponse
    {
        $filename = sprintf('import-%d-%s-report.csv', $batch->id, $batch->type->value);

        return response()->streamDownload(function () use ($batch, $status): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8

            $extra = $this->extraColumns($batch);
            fputcsv($out, [...self::BASE, ...$extra], ',', '"', '\\');

            ImportRow::query()
                ->where('import_batch_id', $batch->id)
                ->when($status !== null, fn ($q) => $q->where('status', $status))
                ->orderBy('row_number')
                ->chunkById(500, function ($rows) use ($out, $extra): void {
                    foreach ($rows as $row) {
                        $report = is_array($row->meta['report'] ?? null) ? $row->meta['report'] : [];
                        fputcsv($out, array_map(self::safe(...), [
                            $row->row_number,
                            $row->status->value,
                            $row->code,
                            $row->message,
                            $row->entity_type,
                            $row->entity_id,
                            ...array_map(fn (string $column): mixed => $report[$column] ?? '', $extra),
                        ]), ',', '"', '\\');
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return list<string>
     */
    private function extraColumns(ImportBatch $batch): array
    {
        if ($batch->isPurged()) {
            return [];
        }

        $row = ImportRow::query()->where('import_batch_id', $batch->id)->whereNotNull('meta')->first();
        $report = $row?->meta['report'] ?? null;

        return is_array($report) ? array_map('strval', array_keys($report)) : [];
    }

    /** Neutralises spreadsheet formula injection: a cell that starts with = + - @ is written as text. */
    public static function safe(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
