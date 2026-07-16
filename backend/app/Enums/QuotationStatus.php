<?php

namespace App\Enums;

enum QuotationStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case UnderDiscussion = 'under_discussion';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
