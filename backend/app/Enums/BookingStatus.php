<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Submitted = 'submitted';
    case PendingReview = 'pending_review';
    case QuotationReady = 'quotation_ready';
    case UnderDiscussion = 'under_discussion';
    case Accepted = 'accepted';
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
