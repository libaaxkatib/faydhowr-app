<?php

namespace App\Support\Hr\Documents;

/** What was written for a newly uploaded document: where, how big, and its SHA-256 (all verified before this exists). */
final readonly class StoredDocumentFile
{
    public function __construct(
        public string $disk,
        public string $path,
        public int $size,
        public string $sha256,
    ) {}
}
