<?php

namespace App\DataTransferObjects\Search;

use App\Enums\Search\SearchType;

final readonly class SearchQueryData
{
    public function __construct(
        public string $query,
        public SearchType $type,
        public int $perPage,
    ) {}
}
