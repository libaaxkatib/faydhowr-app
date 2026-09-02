<?php

namespace App\Enums\Marketing;

enum FollowUpAction: string
{
    case Created = 'created';
    case Rescheduled = 'rescheduled';
    case Completed = 'completed';
    case FeedbackUpdated = 'feedback_updated';
    case StatusUpdated = 'status_updated';
}
