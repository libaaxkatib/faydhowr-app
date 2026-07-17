<?php

namespace App\Exceptions\Reports;

use App\Enums\ReportType;
use DomainException;

class UnsupportedReportTypeException extends DomainException
{
    public static function forType(ReportType|string $type): self
    {
        $key = $type instanceof ReportType ? $type->value : $type;

        return new self("Report type [{$key}] is not supported.");
    }
}
