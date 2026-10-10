<?php

namespace App\Support\Hr\Payroll;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Effective-dated monthly salary history for one employee. Periods are
 * inclusive on both ends; an open-ended period has `to = null`. Overlapping
 * periods are rejected outright (a day must have exactly one applicable
 * salary); a gap is allowed in the history but any payroll day that falls in
 * a gap is rejected by the calculators rather than guessed.
 */
final class SalaryTimeline
{
    /** @var list<array{from: DateTimeImmutable, to: ?DateTimeImmutable, monthly_cents: int}> */
    private array $periods = [];

    /**
     * @param  list<array{from: string, to?: ?string, monthly_cents: int}>  $periods
     */
    public function __construct(array $periods)
    {
        foreach ($periods as $period) {
            $from = new DateTimeImmutable($period['from']);
            $to = isset($period['to']) ? new DateTimeImmutable($period['to']) : null;

            if ($to !== null && $to < $from) {
                throw new InvalidArgumentException('A salary period cannot end before it starts.');
            }

            if ($period['monthly_cents'] < 0) {
                throw new InvalidArgumentException('A monthly salary cannot be negative.');
            }

            $this->periods[] = ['from' => $from, 'to' => $to, 'monthly_cents' => $period['monthly_cents']];
        }

        usort($this->periods, fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        foreach ($this->periods as $index => $period) {
            $next = $this->periods[$index + 1] ?? null;

            if ($next !== null && ($period['to'] === null || $period['to'] >= $next['from'])) {
                throw new InvalidArgumentException('Salary periods overlap.');
            }
        }
    }

    public function monthlyCentsOn(DateTimeImmutable $day): ?int
    {
        foreach ($this->periods as $period) {
            if ($day >= $period['from'] && ($period['to'] === null || $day <= $period['to'])) {
                return $period['monthly_cents'];
            }
        }

        return null;
    }
}
