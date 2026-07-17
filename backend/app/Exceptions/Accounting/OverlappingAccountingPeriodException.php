<?php

namespace App\Exceptions\Accounting;

use Carbon\CarbonInterface;
use DomainException;

class OverlappingAccountingPeriodException extends DomainException
{
    public static function forRange(CarbonInterface $startDate, CarbonInterface $endDate): self
    {
        return new self(sprintf(
            'An accounting period overlapping [%s .. %s] already exists. Periods must not overlap; only one period may contain a given date.',
            $startDate->toDateString(),
            $endDate->toDateString(),
        ));
    }
}
