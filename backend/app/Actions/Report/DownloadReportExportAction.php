<?php

namespace App\Actions\Report;

use App\Contracts\Reports\Storage\ReportStorageInterface;
use App\Enums\ReportExportStatus;
use App\Exceptions\Reports\ReportExportNotDownloadableException;
use App\Models\ReportExport;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadReportExportAction
{
    public function __construct(private ReportStorageInterface $reportStorage) {}

    /**
     * Verify the export is downloadable and delegate streaming to the
     * storage abstraction. Only completed exports may be downloaded.
     *
     * @throws ReportExportNotDownloadableException
     */
    public function handle(ReportExport $export): StreamedResponse
    {
        if ($export->status === ReportExportStatus::Failed) {
            throw ReportExportNotDownloadableException::failed();
        }

        if ($export->status !== ReportExportStatus::Completed) {
            throw ReportExportNotDownloadableException::notReady();
        }

        if ($export->file_path === null || ! $this->reportStorage->exists($export->file_path)) {
            throw ReportExportNotDownloadableException::fileMissing();
        }

        return $this->reportStorage->download($export);
    }
}
