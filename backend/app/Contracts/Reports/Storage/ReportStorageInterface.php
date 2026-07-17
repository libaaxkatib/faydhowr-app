<?php

namespace App\Contracts\Reports\Storage;

use App\Models\ReportExport;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface ReportStorageInterface
{
    /**
     * Reserve the storage path for the given export without writing anything.
     */
    public function reservePath(ReportExport $export): string;

    /**
     * Write the rendered export document to the reserved path.
     */
    public function write(ReportExport $export, string $path, string $contents): void;

    /**
     * Write a placeholder export file to the reserved path. Used for report
     * types whose summary services do not exist yet.
     */
    public function writePlaceholder(ReportExport $export, string $path): void;

    public function exists(string $path): bool;

    public function delete(string $path): bool;

    /**
     * Stream the export file as a download, preserving the report type,
     * export id, and format extension in the download filename.
     */
    public function download(ReportExport $export): StreamedResponse;
}
