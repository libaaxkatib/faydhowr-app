<?php

namespace App\Repositories\Reports;

use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportExportStatus;
use App\Models\ReportExport;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

class ReportExportRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): ReportExport
    {
        return ReportExport::query()->create($attributes);
    }

    public function findOrFail(int $id): ReportExport
    {
        return ReportExport::query()->findOrFail($id);
    }

    /**
     * Cursor-paginated export history with validated filters applied before
     * pagination and deterministic ordering on created_at plus id.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateHistory(
        array $filters,
        ReportCursorPagination $pagination,
        string $sortDirection = 'desc',
    ): CursorPaginator {
        return ReportExport::query()
            ->when(($filters['status'] ?? null) !== null, fn (Builder $query): Builder => $query->where('status', $filters['status']))
            ->when(($filters['report_type'] ?? null) !== null, fn (Builder $query): Builder => $query->where('report_type', $filters['report_type']))
            ->when(($filters['format'] ?? null) !== null, fn (Builder $query): Builder => $query->where('format', $filters['format']))
            ->when(($filters['requested_by'] ?? null) !== null, fn (Builder $query): Builder => $query->where('requested_by', $filters['requested_by']))
            ->when(($filters['created_from'] ?? null) !== null, fn (Builder $query): Builder => $query->where('created_at', '>=', $filters['created_from']))
            ->when(($filters['created_to'] ?? null) !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $filters['created_to']))
            ->orderBy('created_at', $sortDirection)
            ->orderBy('id', $sortDirection)
            ->cursorPaginate(
                perPage: $pagination->limit(),
                cursor: $pagination->cursor(),
            );
    }

    public function markProcessing(ReportExport $export): ReportExport
    {
        $export->forceFill([
            'status' => ReportExportStatus::Processing,
            'started_at' => now(),
        ])->save();

        return $export;
    }

    public function markCompleted(ReportExport $export, string $filePath): ReportExport
    {
        $export->forceFill([
            'status' => ReportExportStatus::Completed,
            'file_path' => $filePath,
            'completed_at' => now(),
        ])->save();

        return $export;
    }

    public function markFailed(ReportExport $export, string $reason): ReportExport
    {
        $export->forceFill([
            'status' => ReportExportStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ])->save();

        return $export;
    }
}
