<?php

namespace App\Enums\Marketing;

/**
 * docs/HRM_MARKETING_SRS.md §21/§39: the calculation formula is TBD.
 * Records are created and stay at PendingCalculation — no code in this
 * phase transitions a record past this status.
 */
enum CommissionStatus: string
{
    case PendingCalculation = 'pending_calculation';
    case Calculated = 'calculated';
    case Approved = 'approved';
    case Paid = 'paid';
}
