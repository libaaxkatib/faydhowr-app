<?php

namespace App\Support\Hr\Import\Handlers;

use App\Actions\Hr\CreateEmployeeAction;
use App\Enums\EmployeeGender;
use App\Enums\ImportType;
use App\Models\Admin;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Position;
use App\Support\Hr\EmployeeDuplicateFinder;
use App\Support\Hr\Import\ImportNormalizer;
use App\Support\Hr\Import\ImportTypeHandler;
use App\Support\Hr\Import\RowOutcome;
use App\Support\Hr\Import\StagedRow;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * NEW employee registrations. The required fields are exactly those of the registration form
 * (StoreEmployeeRequest): full name, registration phone, gender, location, category and application date.
 * Each imported row goes through the same CreateEmployeeAction as a single registration, so a new employee
 * starts at the Application stage (status Applicant, pipeline stage Damiin Needed) with the same history
 * and audit entries.
 *
 * It never updates an existing employee. The same phone with the same name is an existing employee
 * (skipped); the same phone with another name, or the same full name with another phone, is a suspected
 * duplicate and goes to manual review. The registration phone is the only phone matched on; a company
 * payment/account number column is recognised, ignored and never stored (there is no such employee field).
 */
final class EmployeeRegistrationsHandler implements ImportTypeHandler
{
    public function __construct(
        private readonly CreateEmployeeAction $createEmployee,
        private readonly EmployeeDuplicateFinder $duplicates,
    ) {}

    public function type(): ImportType
    {
        return ImportType::EmployeeRegistrations;
    }

    public function columns(): array
    {
        return [
            'full_name' => ['full name', 'name', 'employee name'],
            'phone' => ['registration phone', 'phone number', 'mobile', 'telephone'],
            'alternate_phone' => ['alternate phone', 'alt phone', 'second phone'],
            'gender' => ['sex'],
            'location' => ['residential location', 'residence', 'area'],
            'category' => ['employee category', 'category name'],
            'application_date' => ['date applied', 'registration date'],
            'age' => [],
            'marital_status' => [],
            'lives_with' => [],
            'reference_name' => ['reference'],
            'secondary_contact_name' => [],
            'secondary_contact_phone' => [],
            'department' => ['department name'],
            'position' => ['position name'],
            'experience' => [],
            'source' => [],
            'notes' => [],
        ];
    }

    public function requiredColumns(): array
    {
        return ['full_name', 'phone', 'gender', 'location', 'category', 'application_date'];
    }

    public function ignoredColumns(): array
    {
        return ['payment account', 'payment account number', 'payment number', 'account number', 'account'];
    }

    public function stage(array $cells, array &$seen): StagedRow
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $cells['full_name'] ?? ''));
        $phone = trim($cells['phone'] ?? '');
        $nameKey = ImportNormalizer::nameKey($name);
        $phoneKey = ImportNormalizer::phoneKey($phone);

        if ($name === '' || $nameKey === '') {
            return StagedRow::invalid('NAME_REQUIRED', 'The full name is required.');
        }

        if (mb_strlen($name) > 150) {
            return StagedRow::invalid('NAME_TOO_LONG', 'The full name is longer than 150 characters.', null, $nameKey, $phoneKey);
        }

        if (! ImportNormalizer::isFullPhoneKey($phoneKey) || mb_strlen($phone) > 40) {
            return StagedRow::invalid('PHONE_INVALID', 'The registration phone must be a full number (at least 9 digits).', null, $nameKey, $phoneKey);
        }

        $gender = mb_strtolower(trim($cells['gender'] ?? ''));
        $gender = ['m' => 'male', 'f' => 'female'][$gender] ?? $gender;

        if (! in_array($gender, EmployeeGender::values(), true)) {
            return StagedRow::invalid('GENDER_INVALID', "The gender must be 'male' or 'female'.", null, $nameKey, $phoneKey);
        }

        $location = trim($cells['location'] ?? '');

        if ($location === '' || mb_strlen($location) > 150) {
            return StagedRow::invalid('LOCATION_INVALID', 'The residential location is required (up to 150 characters).', null, $nameKey, $phoneKey);
        }

        $lookups = $seen['lookups'] ??= $this->lookups();
        $categoryId = $lookups['categories'][ImportNormalizer::nameKey($cells['category'] ?? '')] ?? null;

        if ($categoryId === null) {
            return StagedRow::invalid('CATEGORY_UNKNOWN', 'The category is not one of the registered employee categories.', null, $nameKey, $phoneKey);
        }

        $applicationDate = ImportNormalizer::date($cells['application_date'] ?? '');

        if ($applicationDate === null) {
            return StagedRow::invalid('DATE_INVALID', 'The application date is missing or not a recognised date (use YYYY-MM-DD or DD/MM/YYYY).', null, $nameKey, $phoneKey);
        }

        if ($applicationDate > CarbonImmutable::now('UTC')->addDay()->toDateString()) {
            return StagedRow::invalid('DATE_IN_FUTURE', 'The application date is in the future.', null, $nameKey, $phoneKey);
        }

        $age = trim($cells['age'] ?? '');

        if ($age !== '' && (! ctype_digit($age) || (int) $age < 14 || (int) $age > 100)) {
            return StagedRow::invalid('AGE_INVALID', 'The age must be a whole number from 14 to 100.', null, $nameKey, $phoneKey);
        }

        foreach (['alternate_phone', 'secondary_contact_phone'] as $field) {
            if (mb_strlen(trim($cells[$field] ?? '')) > 40) {
                return StagedRow::invalid('PHONE_TOO_LONG', 'A phone value is longer than 40 characters.', null, $nameKey, $phoneKey);
            }
        }

        $departmentId = null;

        if (trim($cells['department'] ?? '') !== '') {
            $departmentId = $lookups['departments'][ImportNormalizer::nameKey($cells['department'])] ?? null;

            if ($departmentId === null) {
                return StagedRow::invalid('DEPARTMENT_UNKNOWN', 'The department is not registered.', null, $nameKey, $phoneKey);
            }
        }

        $positionId = null;

        if (trim($cells['position'] ?? '') !== '') {
            $positionId = $lookups['positions'][ImportNormalizer::nameKey($cells['position'])] ?? null;

            if ($positionId === null) {
                return StagedRow::invalid('POSITION_UNKNOWN', 'The position is not registered.', null, $nameKey, $phoneKey);
            }
        }

        $payload = array_filter([
            'full_name' => $name,
            'phone' => $phone,
            'alternate_phone' => trim($cells['alternate_phone'] ?? ''),
            'gender' => $gender,
            'location' => $location,
            'employee_category_id' => $categoryId,
            'application_date' => $applicationDate,
            'age' => $age === '' ? null : (int) $age,
            'marital_status' => trim($cells['marital_status'] ?? ''),
            'lives_with' => trim($cells['lives_with'] ?? ''),
            'reference_name' => trim($cells['reference_name'] ?? ''),
            'secondary_contact_name' => trim($cells['secondary_contact_name'] ?? ''),
            'secondary_contact_phone' => trim($cells['secondary_contact_phone'] ?? ''),
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'experience' => trim($cells['experience'] ?? ''),
            'source' => trim($cells['source'] ?? ''),
            'notes' => trim($cells['notes'] ?? ''),
        ], fn ($v): bool => $v !== null && $v !== '');

        if (isset($seen['phones'][$phoneKey])) {
            return StagedRow::valid($payload, $nameKey, $phoneKey)->asRepeatInFile('phone number');
        }

        $seen['phones'][$phoneKey] = true;

        $existing = $this->existing($payload, $nameKey);

        if ($existing['same_person'] !== []) {
            return StagedRow::duplicate('EMPLOYEE_EXISTS', 'This person is already registered. The existing record was left unchanged.', $payload, $nameKey, $phoneKey, ['employee_numbers' => $existing['same_person_numbers']], Employee::class, $existing['same_person'][0]);
        }

        if ($existing['phone_other_name'] !== []) {
            return StagedRow::review('PHONE_SHARED_DIFFERENT_NAME', 'This phone number belongs to an existing employee with a different name. Check whether they are the same person.', $payload, $nameKey, $phoneKey, ['employee_numbers' => $existing['phone_other_name_numbers']]);
        }

        if ($existing['name_other_phone'] !== []) {
            return StagedRow::review('SAME_NAME_DIFFERENT_PHONE', 'An existing employee has the same name with a different phone number. Check whether they are the same person.', $payload, $nameKey, $phoneKey, ['employee_numbers' => $existing['name_other_phone_numbers']]);
        }

        return StagedRow::valid($payload, $nameKey, $phoneKey);
    }

    public function process(ImportRow $row, ImportBatch $batch, Admin $actor): RowOutcome
    {
        $payload = (array) $row->payload;
        $nameKey = ImportNormalizer::nameKey((string) ($payload['full_name'] ?? ''));

        // Re-check the live data. Anything that now exists is skipped, never updated or duplicated.
        $existing = $this->existing($payload, $nameKey);

        if ($existing['same_person'] !== []) {
            return RowOutcome::skipped('EMPLOYEE_EXISTS', 'This person is already registered. The existing record was left unchanged.', Employee::class, $existing['same_person'][0]);
        }

        if ($existing['phone_other_name'] !== [] || $existing['name_other_phone'] !== []) {
            return RowOutcome::skipped('NEEDS_REVIEW_SINCE_PREVIEW', 'A similar employee was registered after the preview, so this row was not imported. Review it manually.');
        }

        $payload['source'] = $payload['source'] ?? "Excel import #{$batch->id}";

        try {
            $employee = $this->createEmployee->handle($payload, $actor);
        } catch (HttpResponseException $e) {
            $error = $e->getResponse()->getData(true)['error_code'] ?? null;

            if ($error === 'DUPLICATE_EMPLOYEE_PHONE') {
                return RowOutcome::skipped('EMPLOYEE_EXISTS', 'An employee with this phone number already exists. Nothing was changed.');
            }

            throw $e;
        }

        return RowOutcome::imported(Employee::class, $employee->id, 'Registered at the Application stage.');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{same_person: list<int>, same_person_numbers: list<string>, phone_other_name: list<int>, phone_other_name_numbers: list<string>, name_other_phone: list<int>, name_other_phone_numbers: list<string>}
     */
    private function existing(array $payload, string $nameKey): array
    {
        $result = [
            'same_person' => [], 'same_person_numbers' => [],
            'phone_other_name' => [], 'phone_other_name_numbers' => [],
            'name_other_phone' => [], 'name_other_phone_numbers' => [],
        ];

        foreach ($this->duplicates->find([
            'phone' => $payload['phone'] ?? null,
            'alternate_phone' => $payload['alternate_phone'] ?? null,
            'full_name' => $payload['full_name'] ?? null,
        ])->all() as $match) {
            $sameName = ImportNormalizer::nameKey($match['full_name']) === $nameKey;
            $byPhone = in_array(EmployeeDuplicateFinder::MATCH_PHONE, $match['matched_on'], true);

            if ($byPhone && $sameName) {
                $bucket = 'same_person';
            } elseif ($byPhone) {
                $bucket = 'phone_other_name';
            } elseif ($sameName) {
                $bucket = 'name_other_phone';
            } else {
                continue;
            }

            $result[$bucket][] = $match['id'];
            $result["{$bucket}_numbers"][] = $match['employee_number'];
        }

        return $result;
    }

    /**
     * @return array{categories: array<string, int>, departments: array<string, int>, positions: array<string, int>}
     */
    private function lookups(): array
    {
        $map = fn ($models): array => $models->mapWithKeys(fn ($m): array => [ImportNormalizer::nameKey($m->name) => $m->id])->all();

        return [
            'categories' => $map(EmployeeCategory::query()->get(['id', 'name'])),
            'departments' => $map(Department::query()->get(['id', 'name'])),
            'positions' => $map(Position::query()->get(['id', 'name'])),
        ];
    }
}
