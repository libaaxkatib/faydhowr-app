<?php

namespace App\Support\Hr\Payroll;

use App\Models\EmployeeAdvance;
use App\Models\EmployeePenalty;
use Illuminate\Database\Eloquent\Model;

/**
 * The two kinds of owed deduction a company's payroll takes from a net (Release 1B): a penalty and a one-month salary advance. They differ only in the
 * names of their amount and date columns, so everything that registers, voids, corrects or waives them is written once and told which kind it handles.
 */
final class DeductionKind
{
    /** @param  class-string<Model>  $model */
    private function __construct(
        public readonly string $key,
        public readonly string $model,
        public readonly string $amountField,
        public readonly string $dateField,
        public readonly string $sourceType,
        public readonly string $label,
        public readonly string $payrollKind,
    ) {}

    public static function of(string $key): self
    {
        return match ($key) {
            'penalty' => new self('penalty', EmployeePenalty::class, 'deduction_amount', 'penalty_date', DeductionBalances::TYPE_PENALTY, 'penalty', 'penalty'),
            'advance' => new self('advance', EmployeeAdvance::class, 'amount', 'advance_date', DeductionBalances::TYPE_ADVANCE, 'salary advance', 'advance'),
            default => throw new \InvalidArgumentException("Unknown deduction kind '{$key}'."),
        };
    }

    /** The fields an audit entry shows before / after a change. @return array<string, mixed> */
    public function snapshot(Model $record): array
    {
        return [
            'id' => $record->getKey(),
            'status' => $record->status,
            'version' => $record->version,
            'amount' => (string) $record->{$this->amountField},
            'date' => $record->{$this->dateField}?->toDateString(),
            'payroll_period' => $record->payroll_period,
            'client_company_id' => $record->client_company_id,
            'reason' => $record->reason,
            'waived_cents' => (int) $record->waived_cents,
            'replaces_id' => $record->replaces_id,
        ];
    }
}
