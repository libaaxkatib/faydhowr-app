<?php

namespace App\Services\Reports\Storage;

use App\Contracts\Reports\Storage\ReportStorageInterface;
use App\Enums\ReportExportFormat;
use App\Models\ReportExport;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LocalReportStorage implements ReportStorageInterface
{
    public function reservePath(ReportExport $export): string
    {
        return sprintf(
            '%s/%d/%s-%s.%s',
            rtrim((string) config('report_exports.exports_directory'), '/'),
            $export->id,
            $export->report_type->value,
            now()->format('Ymd_His'),
            $export->format->fileExtension(),
        );
    }

    public function writePlaceholder(ReportExport $export, string $path): void
    {
        $this->disk()->put($path, '');
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function delete(string $path): bool
    {
        return $this->disk()->delete($path);
    }

    public function download(ReportExport $export): StreamedResponse
    {
        return $this->disk()->download(
            (string) $export->file_path,
            $this->downloadFilename($export),
            ['Content-Type' => $this->mimeType($export->format)],
        );
    }

    private function downloadFilename(ReportExport $export): string
    {
        return sprintf(
            '%s-%d.%s',
            $export->report_type->value,
            $export->id,
            $export->format->fileExtension(),
        );
    }

    private function mimeType(ReportExportFormat $format): string
    {
        return match ($format) {
            ReportExportFormat::Pdf => 'application/pdf',
            ReportExportFormat::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter */
        return Storage::disk((string) config('report_exports.default_disk'));
    }
}
