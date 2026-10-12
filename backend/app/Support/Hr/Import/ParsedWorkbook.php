<?php

namespace App\Support\Hr\Import;

final class ParsedWorkbook
{
    /**
     * @param  list<array{row_number: int, cells: array<string, string>}>  $rows
     * @param  list<string>  $unrecognisedHeaders  headers that are not imported (reported to the user)
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $unrecognisedHeaders = [],
    ) {}
}
