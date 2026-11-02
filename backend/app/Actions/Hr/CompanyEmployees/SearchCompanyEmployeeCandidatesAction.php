<?php

namespace App\Actions\Hr\CompanyEmployees;

use App\Enums\EmployeeStatus;
use App\Enums\WorkAssignmentStatus;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeSeparation;
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
    public function handle(ClientCompany $company, string $term, bool $canActivate = false): array
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
            ->map(fn (Employee $e): array => $this->candidate($e, $company, $canActivate))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function candidate(Employee $e, ClientCompany $company, bool $canActivate): array
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
            ...$this->activation($e, $canActivate),
        ];
    }

    /**
     * Whether the picker may offer an in-place Activate for this person, and by which existing HR step:
     *   - a Waiting or Approved employee: the normal status change to Active ('status');
     *   - a separated (Inactive) employee: the Rehire step ('rehire'); a Rehire-not-eligible mark on the latest separation is advice shown to HR, as in HR.
     * Nobody earlier in the pipeline (applicant, recruitment, practical) is offered: that needs a Super Admin override in HR, not a shortcut here. A new
     * registration whose workflow is incomplete is refused exactly as the HR step itself would refuse it. The endpoints re-check everything on the server.
     *
     * @return array{activation: ?string, can_activate: bool, rehire_eligible: ?bool}
     */
    private function activation(Employee $e, bool $canActivate): array
    {
        $step = match ($e->status) {
            EmployeeStatus::Waiting, EmployeeStatus::Approved => 'status',
            EmployeeStatus::Inactive => 'rehire',
            default => null,
        };

        return [
            'activation' => $step,
            'can_activate' => $canActivate && $step !== null && ! $e->trashed() && ! (new EmployeeRegistrationWorkflow)->blocks($e),
            // Separation data is shown only to someone who can act on it (hr.manage), not to every viewer who merely searches.
            'rehire_eligible' => $step === 'rehire' && $canActivate ? EmployeeSeparation::query()->where('employee_id', $e->id)->latest('id')->value('rehire_eligible') : null,
        ];
    }
}
