<?php

namespace App\Events\Quotation;

use App\Models\Quotation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuotationExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(public Quotation $quotation) {}
}
