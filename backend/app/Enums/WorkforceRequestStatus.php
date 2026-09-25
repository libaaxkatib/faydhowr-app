<?php

namespace App\Enums;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 2 (Waiting & Company Matching): tracks
 * how many of a workforce_requests.quantity_needed have been confirmed via
 * workforce_request_matches. Never hand-edited by UpdateWorkforceRequestAction -
 * only ConfirmWorkforceRequestMatchAction and CancelWorkforceRequestAction move it.
 */
enum WorkforceRequestStatus: string
{
    case Open = 'open';
    case PartiallyFilled = 'partially_filled';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::PartiallyFilled => 'Partially Filled',
            self::Fulfilled => 'Fulfilled',
            self::Cancelled => 'Cancelled',
        };
    }
}
