<?php

namespace App\Enums\Marketing;

/**
 * docs/HRM_MARKETING_SRS.md §21/§39: the calculation formula is TBD, so no code
 * computes an amount. Commission is recorded MANUALLY: an entry starts at
 * PendingCalculation, and becomes Recorded when a Marketing Manager (or Super
 * Admin) types the agreed amount. Nothing here transitions a record to
 * Calculated, Approved or Paid.
 */
enum CommissionStatus: string
{
    case PendingCalculation = 'pending_calculation';
    case Recorded = 'recorded';
    case Calculated = 'calculated';
    case Approved = 'approved';
    case Paid = 'paid';
}
