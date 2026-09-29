<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Support\Hr\SecondaryContact\SecondaryContactClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Issue #1 targeted backfill: reconstructs secondary_contact_name/phone for
 * already-migrated employees from the existing `notes` text (the source
 * workbook is gone — see SecondaryContactClassifier). Read-only unless
 * --commit is passed. Additive only: never touches any other employee field,
 * never touches `notes` itself.
 */
class BackfillSecondaryContactCommand extends Command
{
    protected $signature = 'hr:backfill-secondary-contact {--commit : Actually write to the database}';

    protected $description = 'Issue #1: backfill secondary_contact_name/phone from existing notes text. Read-only unless --commit.';

    public function handle(): int
    {
        $isCommit = (bool) $this->option('commit');

        $employees = Employee::query()
            ->whereRaw('LOWER(notes) LIKE ?', ['%secondary/emergency contact:%'])
            ->whereNull('secondary_contact_name')
            ->whereNull('secondary_contact_phone')
            ->get(['id', 'employee_number', 'notes']);

        $this->info("Candidates found (notes contain the marker, fields still empty): {$employees->count()}");

        $reasons = [];
        $toWrite = [];

        foreach ($employees as $employee) {
            $result = SecondaryContactClassifier::classify((string) $employee->notes);
            $reasons[$result['reason']] = ($reasons[$result['reason']] ?? 0) + 1;

            if (! $result['excluded']) {
                $toWrite[] = [
                    'id' => $employee->id,
                    'employee_number' => $employee->employee_number,
                    'name' => $result['name'],
                    'phone' => $result['phone'],
                ];
            }
        }

        $this->newLine();
        $this->info('=== Classification breakdown ===');
        $this->table(['Reason', 'Count'], array_map(fn ($k, $v) => [$k, $v], array_keys($reasons), array_values($reasons)));

        $this->newLine();
        $this->info('Backfill candidates: '.count($toWrite));
        $this->info('Excluded: '.($employees->count() - count($toWrite)));

        if (! $isCommit) {
            $this->newLine();
            $this->warn('DRY RUN — no database writes occurred. Re-run with --commit to apply.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('=== COMMIT: writing '.count($toWrite).' records ===');

        $expected = count($toWrite);
        $written = 0;

        DB::transaction(function () use ($toWrite, &$written) {
            foreach ($toWrite as $row) {
                $affected = Employee::query()
                    ->where('id', $row['id'])
                    ->whereNull('secondary_contact_name')
                    ->whereNull('secondary_contact_phone')
                    ->update([
                        'secondary_contact_name' => $row['name'],
                        'secondary_contact_phone' => $row['phone'],
                    ]);
                $written += $affected;
            }

            if ($written !== count($toWrite)) {
                throw new \RuntimeException('Mismatch: expected to write '.count($toWrite).", actually wrote {$written}. Rolling back.");
            }
        });

        $this->info("Committed. Rows written: {$written} (expected {$expected}).");

        return self::SUCCESS;
    }
}
