<?php

namespace App\Events\Quotation;

use App\Models\Quotation;
use App\Models\QuotationDiscussionMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuotationDiscussionReplyCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Quotation $quotation,
        public QuotationDiscussionMessage $message,
    ) {}
}
