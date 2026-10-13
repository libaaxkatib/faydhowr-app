<?php

namespace App\Support\Hr\OfficeStaff;

use App\Enums\EmployeeStatus;
use App\Enums\LocationType;
use App\Models\Employee;
use App\Models\EmployeeDocument;

/**
 * What the Office Staff workflow shows about one employee: identity, emergency contact, guarantor, current
 * salary and position, whether they are already Office Staff, and which of the four document groups are on
 * file. It never contains the payment/account number, and never a file path or URL: documents are listed by
 * name and status only, and are downloaded through the existing authenticated document endpoint.
 */
final class OfficeStaffSummary
{
    /** Document groups the workflow asks for, mapped to the existing employee document categories (no new categories). */
    public const DOCUMENT_GROUPS = [
        'identity' => ['label' => 'Identification document', 'category' => 'Identity Documents', 'required' => true],
        'employment' => ['label' => 'Employment / appointment document', 'category' => 'Employment Documents', 'required' => true],
        'guarantor' => ['label' => 'Guarantor documents', 'category' => 'Guarantor Documents', 'required' => true],
        'other' => ['label' => 'Other supporting documents', 'category' => 'Other HR Documents', 'required' => false],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function for(Employee $employee): array
    {
        $employee->loadMissing([
            'category', 'position', 'guarantor',
            'activeWorkAssignments.workLocation.clientCompany', 'activeWorkAssignments.clientCompany', 'activeWorkAssignments.position',
        ]);

        $assignments = $employee->activeWorkAssignments;
        $office = $assignments->first(fn ($a): bool => $a->workLocation?->location_type === LocationType::Office);
        $current = $office ?? $assignments->first();

        [$eligible, $reason] = self::eligibility($employee, $office !== null);

        return [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'full_name' => $employee->full_name,
            'phone' => $employee->phone,
            'status' => $employee->status->value,
            'employee_category_id' => $employee->employee_category_id,
            'category_name' => $employee->category?->name,
            'position_id' => $employee->position_id ?? $current?->position_id,
            'position_name' => $employee->position?->name ?? $current?->position?->name,
            'emergency_contact' => [
                'name' => $employee->secondary_contact_name,
                'phone' => $employee->secondary_contact_phone,
            ],
            'guarantor' => $employee->guarantor === null ? null : [
                'name' => $employee->guarantor->guarantor_name,
                'phone' => $employee->guarantor->guarantor_phone,
                'relationship' => $employee->guarantor->relationship,
                'verified' => $employee->guarantor->verified_at !== null,
            ],
            'current_salary' => $current === null ? null : [
                'amount' => number_format((float) $current->salary_amount, 2, '.', ''),
                'currency' => $current->salary_currency,
                'frequency' => $current->salary_frequency?->value ?? (string) $current->salary_frequency,
            ],
            'is_office_staff' => $office !== null,
            'active_assignments' => $assignments->map(fn ($a): array => [
                'id' => $a->id,
                'label' => $a->workLocation?->location_type === LocationType::Office
                    ? 'Fayadhowr Office'
                    : ($a->clientCompany?->name ?? $a->workLocation?->clientCompany?->name ?? 'Company'),
                'salary' => number_format((float) $a->salary_amount, 2, '.', '').' '.$a->salary_currency,
            ])->values()->all(),
            'can_add' => $eligible,
            'cannot_add_reason' => $reason,
            'documents' => self::documents($employee),
        ];
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private static function eligibility(Employee $employee, bool $alreadyOffice): array
    {
        if ($alreadyOffice) {
            return [false, 'This employee is already Office Staff.'];
        }

        if ($employee->status !== EmployeeStatus::Active) {
            return [false, "Only Active employees can be assigned. This employee is {$employee->status->value}; complete their status change first."];
        }

        return [true, null];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function documents(Employee $employee): array
    {
        $current = EmployeeDocument::query()
            ->where('employee_id', $employee->id)
            ->where('is_current', true)
            ->with('category')
            ->get()
            ->groupBy(fn (EmployeeDocument $d): string => (string) $d->category?->name);

        $groups = [];

        foreach (self::DOCUMENT_GROUPS as $key => $group) {
            $files = $current->get($group['category'], collect());

            $groups[] = [
                'key' => $key,
                'label' => $group['label'],
                'category' => $group['category'],
                'required' => $group['required'],
                'status' => $files->isEmpty() ? 'missing' : 'on_file',
                'files' => $files->map(fn (EmployeeDocument $d): array => [
                    'id' => $d->id,
                    'file_name' => $d->file_name,
                    'verification_status' => $d->verification_status->value,
                    'uploaded_at' => $d->created_at?->toDateString(),
                ])->values()->all(),
            ];
        }

        return $groups;
    }
}
