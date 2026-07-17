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
     * Write the placeholder export file to the reserved path.
     * Actual PDF/XLSX rendering is implemented in a later phase.
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
