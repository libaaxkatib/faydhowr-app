<?php

namespace App\DataTransferObjects\Customer;

readonly class CreateNoteData
{
    public function __construct(public string $body) {}
}
