<?php

namespace App\Actions\Hr\CompanyEmployees;

use App\Enums\EmployeeStatus;
use App\Enums\WorkAssignmentStatus;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Support\Hr\ConcurrentAssignmentGuard;
use App\Support\Hr\EmployeeRegistrationWorkflow;

/**
 * The searchable employee picker. It searches the existing HR register by its unique identifiers (Employee ID, employee number) and,
 * for convenience, by name or registration phone - but every result carries the identifiers, so two people with the same name are
 * told apart, and the choice that is submitted is always the id, never the name. Nobody is created here.
 */
class SearchCompanyEmployeeCandidatesAction
{
    public const MIN_LENGTH = 2;

    public const LIMIT = 15;

    /** @return list<array<string, mixed>> */
    public function handle(ClientCompany $company, string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return [];
        }

        $needle = '%'.mb_strtolower($term).'%';

        $employees = Employee::query()
            ->with(['category', 'workAssignments' => fn ($q) => $q->where('status', WorkAssignmentStatus::Active)->with('clientCompany')])
            ->where(function ($q) use ($term, $needle) {
                if (ctype_digit($term)) {
                    $q->orWhere('id', (int) $term);
                }

                $q->orWhereRaw('lower(employee_number) like ?', [$needle])
                    ->orWhereRaw('lower(full_name) like ?', [$needle])
                    ->orWhereRaw('lower(phone) like ?', [$needle]);
            })
            ->limit(60)
            ->get();

        $lower = mb_strtolower($term);

        return $employees
            ->sortBy(fn (Employee $e): int => match (true) {
                mb_strtolower((string) $e->employee_number) === $lower || (string) $e->id === $term => 0,
                str_starts_with(mb_strtolower((string) $e->employee_number), $lower) => 1,
                default => 2,
            })
            ->take(self::LIMIT)
            ->map(fn (Employee $e): array => $this->candidate($e, $company))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function candidate(Employee $e, ClientCompany $company): array
    {
        $active = $e->workAssignments;
        $here = $active->contains(fn ($a): bool => (int) $a->client_company_id === (int) $company->id);

        $elsewhere = $active->filter(fn ($a): bool => $a->client_company_id !== null && (int) $a->client_company_id !== (int) $company->id && ($a->end_date === null || $a->end_date->gte(now()->startOfDay())));

        $reason = match (true) {
            $e->status !== EmployeeStatus::Active => "{$e->status->value} in HR - only Active employees can be assigned",
            (new EmployeeRegistrationWorkflow)->blocks($e) => 'new registration - the registration workflow (guarantor, contract, uniform, training, practice) is not complete',
            $here => 'already has an active assignment at this company',
            ! ConcurrentAssignmentGuard::enabled() && $elsewhere->isNotEmpty() => 'already assigned at '.$elsewhere->map(fn ($a): string => (string) $a->clientCompany?->name)->unique()->implode(', ').'; multiple-company assignments are not enabled',
            default => null,
        };

        return [
            'id' => $e->id,
            'employee_number' => $e->employee_number,
            'full_name' => $e->full_name,
            'status' => $e->status->value,
            'phone' => $e->phone,
            'category' => $e->category?->name,
            'employment_type' => $e->employment_type,
            'current_companies' => $active->map(fn ($a): string => (string) ($a->clientCompany?->name ?? 'Fayadhowr Office'))->unique()->values()->all(),
            'already_here' => $here,
            'eligible' => $reason === null,
            'ineligible_reason' => $reason,
        ];
    }
}
