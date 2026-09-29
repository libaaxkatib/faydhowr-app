<?php

namespace Tests\Feature\Hr;

use App\Enums\EmployeePipelineStage;
use App\Enums\EmployeeSeparationReason;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeSeparation;
use App\Models\EmployeeStatusHistory;
use App\Support\Hr\ExcelMigration\ExcelMigrationCommitter;
use App\Support\Hr\ExcelMigration\ExcelMigrationReport;
use App\Support\Hr\ExcelMigration\ResolvedPerson;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * ExcelMigrationCommitter writes ONLY $report->readyToImport — $report->manualReview
 * is never even read by it. These tests exercise the actual database writes (real
 * employees/employee_status_histories/employee_separations rows) that the dry-run
 * report only describes, using RefreshDatabase so nothing here touches a real database.
 */
class ExcelMigrationCommitterTest extends TestCase
{
    use RefreshDatabase;

    public function test_ready_to_import_person_is_written_with_all_resolved_fields(): void
    {
        $report = new ExcelMigrationReport;
        $report->readyToImport = [
            $this->buildPerson([
                'normalizedPhone' => '615000111',
                'fullName' => 'Amina Hassan',
                'matchedCategory' => 'Home Team',
                'categorySpecialization' => 'Full Time - Jiif',
                'guarantorNeedFlag' => true,
                'status' => EmployeeStatus::Applicant,
                'pipelineStage' => EmployeePipelineStage::NeedTraining,
                'applicationDate' => Carbon::parse('2025-03-01'),
                'sourceSheets' => ['REGISTRATION'],
                'sourceRefs' => ['REGISTRATION:12'],
            ]),
        ];

        $result = app(ExcelMigrationCommitter::class)->commit($report, null, '/tmp/REGISTRATION faya.xlsx');

        self::assertSame(1, $result->employeesCreated);
        self::assertSame(1, $result->statusHistoriesCreated);
        self::assertSame(0, $result->separationsCreated);
        self::assertSame(0, $result->skippedAsAlreadyImported);

        $employee = Employee::query()->sole();
        self::assertSame('615000111', $employee->phone);
        self::assertSame('Amina Hassan', $employee->full_name);
        self::assertSame('Home Team', $employee->category->name);
        self::assertSame('Full Time - Jiif', $employee->category_specialization);
        self::assertTrue($employee->guarantor_needed);
        self::assertFalse($employee->profile_complete);
        self::assertSame(EmployeeStatus::Applicant, $employee->status);
        self::assertSame(EmployeePipelineStage::NeedTraining, $employee->pipeline_stage);
        self::assertSame('2025-03-01', $employee->application_date->toDateString());
        self::assertStringContainsString('REGISTRATION', $employee->notes);
        self::assertNull($employee->created_by);

        $history = EmployeeStatusHistory::query()->sole();
        self::assertSame($employee->id, $history->employee_id);
        self::assertNull($history->from_status);
        self::assertSame(EmployeeStatus::Applicant, $history->to_status);
    }

    public function test_secondary_contact_is_written_to_dedicated_columns_not_appended_to_notes(): void
    {
        $report = new ExcelMigrationReport;
        $report->readyToImport = [
            $this->buildPerson([
                'normalizedPhone' => '615000888',
                'fullName' => 'Has Secondary Contact',
                'secondaryContactName' => 'Hooyo Muna',
                'secondaryContactPhone' => '616605866',
            ]),
        ];

        app(ExcelMigrationCommitter::class)->commit($report, null, 'x.xlsx');

        $employee = Employee::query()->sole();
        self::assertSame('Hooyo Muna', $employee->secondary_contact_name);
        self::assertSame('616605866', $employee->secondary_contact_phone);
        self::assertStringNotContainsString('Secondary/emergency contact', $employee->notes);
        self::assertStringNotContainsString('616605866', $employee->notes);
    }

    public function test_person_without_secondary_contact_gets_null_dedicated_columns(): void
    {
        $report = new ExcelMigrationReport;
        $report->readyToImport = [
            $this->buildPerson([
                'normalizedPhone' => '615000999',
                'fullName' => 'No Secondary Contact',
            ]),
        ];

        app(ExcelMigrationCommitter::class)->commit($report, null, 'x.xlsx');

        $employee = Employee::query()->sole();
        self::assertNull($employee->secondary_contact_name);
        self::assertNull($employee->secondary_contact_phone);
    }

    public function test_person_with_no_reliable_phone_is_written_with_null_phone_not_the_sentinel(): void
    {
        $report = new ExcelMigrationReport;
        $report->readyToImport = [
            $this->buildPerson([
                'normalizedPhone' => 'no-phone:wiilasha:44',
                'fullName' => 'No Phone Person',
            ]),
        ];

        app(ExcelMigrationCommitter::class)->commit($report, null, 'x.xlsx');

        $employee = Employee::query()->sole();
        self::assertNull($employee->phone);
    }

    public function test_cancelled_person_gets_a_separation_row_with_no_fabricated_date(): void
    {
        $report = new ExcelMigrationReport;
        $report->readyToImport = [
            $this->buildPerson([
                'normalizedPhone' => '615000222',
                'fullName' => 'Cancelled Person',
                'status' => EmployeeStatus::Inactive,
                'isCancellation' => true,
                'cancellationSources' => ['RED fill in REGISTRATION (row 9)'],
                'cancellationReason' => 'Moved to another city',
                'cancellationSourceContext' => 'Stage: "moved". Other info: "left for Kismayo".',
            ]),
        ];

        $result = app(ExcelMigrationCommitter::class)->commit($report, null, 'x.xlsx');

        self::assertSame(1, $result->separationsCreated);

        $separation = EmployeeSeparation::query()->sole();
        self::assertSame(EmployeeSeparationReason::Other, $separation->reason);
        self::assertNull($separation->separation_date);
        self::assertStringContainsString('Moved to another city', $separation->notes);
        self::assertNull($separation->separated_by);
    }

    public function test_manual_review_people_are_never_written(): void
    {
        $report = new ExcelMigrationReport;
        $report->readyToImport = [];
        $report->manualReview = [
            $this->buildPerson(['normalizedPhone' => '615000333', 'fullName' => 'Manual Review Person']),
        ];

        $result = app(ExcelMigrationCommitter::class)->commit($report, null, 'x.xlsx');

        self::assertSame(0, $result->employeesCreated);
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_person_already_imported_by_phone_is_skipped_not_duplicated(): void
    {
        $category = EmployeeCategory::query()->where('name', 'General Cleaning')->sole();

        Employee::query()->create([
            'employee_number' => 'EMP-000001',
            'full_name' => 'Existing Employee',
            'phone' => '615000444',
            'employee_category_id' => $category->id,
            'status' => EmployeeStatus::Active,
        ]);

        $report = new ExcelMigrationReport;
        $report->readyToImport = [
            $this->buildPerson(['normalizedPhone' => '615000444', 'fullName' => 'Duplicate Attempt']),
        ];

        $result = app(ExcelMigrationCommitter::class)->commit($report, null, 'x.xlsx');

        self::assertSame(0, $result->employeesCreated);
        self::assertSame(1, $result->skippedAsAlreadyImported);
        self::assertSame(['615000444'], $result->skippedDuplicatePhones);
        $this->assertDatabaseCount('employees', 1);
    }

    public function test_unresolvable_category_aborts_the_whole_commit(): void
    {
        $report = new ExcelMigrationReport;
        $report->readyToImport = [
            $this->buildPerson(['normalizedPhone' => '615000555', 'fullName' => 'Good Person']),
            $this->buildPerson(['normalizedPhone' => '615000666', 'fullName' => 'Bad Category Person', 'matchedCategory' => 'Not A Real Category']),
        ];

        try {
            app(ExcelMigrationCommitter::class)->commit($report, null, 'x.xlsx');
            self::fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_commit_fires_a_single_summary_audit_event(): void
    {
        Event::fake([AuditEvent::class]);

        $admin = Admin::factory()->create();

        $report = new ExcelMigrationReport;
        $report->readyToImport = [
            $this->buildPerson(['normalizedPhone' => '615000777', 'fullName' => 'Audited Person']),
        ];

        app(ExcelMigrationCommitter::class)->commit($report, $admin, 'x.xlsx');

        Event::assertDispatched(AuditEvent::class, function (AuditEvent $event) use ($admin): bool {
            return $event->adminId === $admin->id
                && $event->metadata['employees_created'] === 1;
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function buildPerson(array $overrides = []): ResolvedPerson
    {
        $defaults = [
            'normalizedPhone' => '615000000',
            'alternatePhone' => null,
            'fullName' => 'Test Person',
            'location' => null,
            'age' => null,
            'maritalStatus' => null,
            'livesWith' => null,
            'source' => null,
            'experience' => null,
            'rawJobTitle' => 'general cleaning',
            'matchedCategory' => 'General Cleaning',
            'matchedCategoryIsConfident' => true,
            'categorySpecialization' => null,
            'isSupervisor' => false,
            'status' => EmployeeStatus::Applicant,
            'pipelineStage' => null,
            'isCancellation' => false,
            'cancellationSources' => [],
            'cancellationReason' => null,
            'cancellationSourceContext' => null,
            'applicationDate' => null,
            'applicationDateInferred' => false,
            'applicationDateInferenceNote' => null,
            'waitingSince' => null,
            'trainingFeeStatus' => null,
            'trainingFeeRawUnmapped' => null,
            'secondaryContactPhone' => null,
            'secondaryContactName' => null,
            'otherInfo' => null,
            'historyNote' => 'Sheet: REGISTRATION.',
            'isYellowFlagged' => false,
            'isGreenFlagged' => false,
            'guarantorNeedFlag' => false,
            'sourceSheets' => ['REGISTRATION'],
            'sourceRefs' => ['REGISTRATION:1'],
            'isReadyToImport' => true,
            'reviewReasons' => [],
            'profileIncompleteReasons' => ['No guarantor documentation on file.'],
        ];

        $args = array_merge($defaults, $overrides);

        return new ResolvedPerson(...$args);
    }
}
