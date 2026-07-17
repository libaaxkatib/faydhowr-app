<?php

namespace App\Exceptions\Reports;

use DomainException;

class ReportExportNotDownloadableException extends DomainException
{
    private function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly string $errorCode,
    ) {
        parent::__construct($message);
    }

    public static function notReady(): self
    {
        return new self(
            'Report export is not ready for download yet.',
            409,
            'EXPORT_NOT_READY',
        );
    }

    public static function failed(): self
    {
        return new self(
            'Report export failed and cannot be downloaded.',
            410,
            'EXPORT_FAILED',
        );
    }

    public static function fileMissing(): self
    {
        return new self(
            'Report export file is missing from storage.',
            404,
            'EXPORT_FILE_MISSING',
        );
    }
}
