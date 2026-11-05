<?php

namespace App\Support\Hr\Payroll;

use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The freshness guard of a company's payroll draft (Release 1B): a fingerprint of the penalties and advances the draft was generated from.
 *
 * A penalty or an advance may be registered, corrected, voided or waived while a draft exists. The stored lines were calculated before that, so reviewing
 * or finalizing them would lock in a wrong deduction (the old "a deduction entered after Generate is lost at Finalize" problem). Generate stores the
 * fingerprint; Review and Finalize recompute it and refuse a section whose inputs changed, telling the user to Generate that company again.
 *
 * The fingerprint covers what payroll of the month would deduct for the people on the section's lines, from the section's company (or from no company: a
 * record from before company ownership): every active record with something still owed, its version, amount and owed balance. A section generated before
 * this release has no fingerprint and is judged as before, so the locked sections of earlier months are never touched.
 */
final class DeductionInputsFingerprint
{
    public static function forSection(PayrollRunSection $section, string $period): string
    {
        $employeeIds = PayrollItem::query()->where('section_id', $section->id)->pluck('employee_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $owed = DeductionBalances::owedFor($employeeIds, $period);
        $company = $section->client_company_id;
        $rows = [];

        foreach (['penalties' => 'P', 'advances' => 'A'] as $key => $type) {
            foreach ($owed[$key] as $records) {
                foreach ($records as $record) {
                    if ($record->client_company_id !== null && (int) $record->client_company_id !== (int) $company) {
                        continue;
                    }

                    $rows[] = [$type, (int) $record->id, (int) $record->version, $record->amountCents(), (int) $record->owed_cents];
                }
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return hash('sha256', json_encode([$employeeIds, $rows], JSON_THROW_ON_ERROR));
    }

    /** True when the section was generated with a fingerprint and the inputs are not the same any more. */
    public static function changed(PayrollRunSection $section, string $period): bool
    {
        return $section->deduction_inputs_hash !== null && ! hash_equals($section->deduction_inputs_hash, self::forSection($section, $period));
    }

    /** @throws HttpResponseException 409 when a penalty / advance changed after this company was generated */
    public static function assertFresh(PayrollRunSection $section, PayrollRun $run): void
    {
        if (! self::changed($section, $run->period)) {
            return;
        }

        $name = $section->clientCompany?->name ?? 'this company';

        throw new HttpResponseException(ApiResponse::error(
            "A penalty or an advance for {$name} was registered, corrected, voided or waived after payroll {$run->period} was generated, so the lines no longer match. Nothing was changed. Generate {$name} again, then review and finalize it.",
            'PAYROLL_INPUTS_CHANGED',
            409,
        ));
    }
}
