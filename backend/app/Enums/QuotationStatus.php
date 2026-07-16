<?php

namespace App\Enums;

enum QuotationStatus: string
{
    case PendingReview = 'pending_review';
    case QuotationReady = 'quotation_ready';
    case UnderDiscussion = 'under_discussion';
    case Accepted = 'accepted';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
