<?php

namespace App\Events\Quotation;

use App\Models\Quotation;
use App\Models\QuotationRevision;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuotationIssued
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Quotation $quotation,
        public QuotationRevision $revision,
    ) {}
}
