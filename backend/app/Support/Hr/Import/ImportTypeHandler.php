<?php

namespace App\Support\Hr\Import;

use App\Enums\ImportType;
use App\Models\Admin;
use App\Models\ImportBatch;
use App\Models\ImportRow;

/**
 * One handler per import workflow. A handler decides how each workbook row is classified at upload
 * (stage) and what confirming it does (process). It never reads or writes the uploaded file.
 */
interface ImportTypeHandler
{
    public function type(): ImportType;

    /**
     * Canonical column name => accepted header spellings (already normalised: lower-case, single spaces).
     * The canonical name itself is always accepted.
     *
     * @return array<string, list<string>>
     */
    public function columns(): array;

    /** @return list<string> canonical names that must be present as headers */
    public function requiredColumns(): array;

    /**
     * Headers that are recognised but deliberately not imported (reported to the user, never stored).
     *
     * @return list<string>
     */
    public function ignoredColumns(): array;

    /**
     * Classify one row. Must not write anything. `$seen` is shared across the rows of one upload so a
     * handler can spot repeats inside the file.
     *
     * @param  array<string, string>  $cells  canonical column => trimmed cell text
     * @param  array<string, mixed>  $seen
     */
    public function stage(array $cells, array &$seen): StagedRow;

    /**
     * Apply one VALID row. Runs inside the caller's per-row transaction. Must re-check the live
     * database (the preview may be old) and skip - never update - anything that already exists.
     */
    public function process(ImportRow $row, ImportBatch $batch, Admin $actor): RowOutcome;
}
