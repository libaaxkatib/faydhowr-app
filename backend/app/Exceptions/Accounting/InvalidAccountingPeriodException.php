<?php

namespace App\Exceptions\Accounting;

use Carbon\CarbonInterface;
use DomainException;

class InvalidAccountingPeriodException extends DomainException
{
    public static function inverseDateRange(CarbonInterface $startDate, CarbonInterface $endDate): self
    {
        return new self(sprintf(
            'Accounting period start date [%s] must not be after its end date [%s].',
            $startDate->toDateString(),
            $endDate->toDateString(),
        ));
    }
}
