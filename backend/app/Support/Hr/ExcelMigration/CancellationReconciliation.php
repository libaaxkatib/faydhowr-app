<?php

namespace App\Support\Hr\ExcelMigration;

/**
 * Explicit, per-row, person-level reconciliation of the two confirmed
 * cancellation sources (RED REGISTRATION highlight, CANCELED sheet
 * membership). Every source row is classified as exactly one of:
 *
 *   A — valid, became its own uniquely-identified cancelled person
 *   B — a duplicate of another row that resolved into the SAME person
 *       (i.e. the same person appears twice — correctly counted once)
 *   C — blank/non-person row (no name at all — cannot become an Employee)
 *   D — phone conflict: shares a phone with a non-matching name elsewhere —
 *       withheld for manual review, never auto-merged
 *   E — no valid phone number, but still resolved as its own person
 *       (identity unverified against other sheets, but not discarded)
 *
 * Built by ExcelMigrationService from the same resolution pass used for the
 * rest of the report — this is a read/reporting-only view over that result,
 * nothing here re-derives status or writes anything.
 */
final class CancellationReconciliation
{
    public int $redRowsTotal = 0;

    public int $redClassA = 0;

    public int $redClassB = 0;

    public int $redClassC = 0;

    public int $redClassD = 0;

    public int $redClassE = 0;

    public int $canceledRowsTotal = 0;

    public int $canceledClassA = 0;

    public int $canceledClassB = 0;

    public int $canceledClassC = 0;

    public int $canceledClassD = 0;

    public int $canceledClassE = 0;

    /** People whose cancellation is confirmed by BOTH a red row AND a CANCELED-sheet row. */
    public int $overlapPersons = 0;

    /** @var list<array{sheet: string, row: int, name: string, classification: string, reason: string}> */
    public array $rowDetails = [];

    public function finalUniqueCancelledCount(): int
    {
        // Exactly one row per person-group is ever classified A or E (the first row
        // encountered — REGISTRATION is always processed before the CANCELED sheet, so a
        // person present in both sources is classified via their RED row as A, and their
        // CANCELED-sheet row as B, never both A). That means summing every A/E row across
        // both sources already counts each unique person exactly once — no further
        // subtraction for overlap is correct; $overlapPersons is a purely informational
        // cross-tabulation (how many final people draw from both sources), not a quantity
        // to net out here. (An earlier version of this method incorrectly subtracted it,
        // which double-counted the subtraction for anyone whose overlap row was already B.)
        return $this->redClassA + $this->redClassE + $this->canceledClassA + $this->canceledClassE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'red_rows_total' => $this->redRowsTotal,
            'red_valid_unique_person_rows' => $this->redClassA,
            'red_duplicate_rows' => $this->redClassB,
            'red_blank_non_person_rows' => $this->redClassC,
            'red_phone_conflict_rows' => $this->redClassD,
            'red_no_valid_phone_still_resolved_rows' => $this->redClassE,
            'canceled_sheet_rows_total' => $this->canceledRowsTotal,
            'canceled_valid_unique_person_rows' => $this->canceledClassA,
            'canceled_duplicate_rows' => $this->canceledClassB,
            'canceled_blank_non_person_rows' => $this->canceledClassC,
            'canceled_phone_conflict_rows' => $this->canceledClassD,
            'canceled_no_valid_phone_still_resolved_rows' => $this->canceledClassE,
            'overlap_persons_in_both_sources' => $this->overlapPersons,
            'final_unique_cancelled_people' => $this->finalUniqueCancelledCount(),
        ];
    }
}
