<?php

namespace App\Enums;

enum ImportBatchStatus: string
{
    /** Parsed and previewed; nothing has been imported yet. */
    case Staged = 'staged';
    /** Confirmed by a Super Admin; rows are being imported in bounded batches. */
    case Processing = 'processing';
    case Completed = 'completed';
    /** Discarded before confirmation; staging data is purged immediately. */
    case Cancelled = 'cancelled';
}
