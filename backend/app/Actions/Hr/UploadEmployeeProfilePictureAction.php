<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeDocumentCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * docs/HRM_MARKETING_SRS.md HR §29: the picture is a categorized document
 * (reusing StoreEmployeeDocumentAction's supersede-not-delete behaviour so
 * a replaced picture keeps its history), referenced directly from the
 * employee row for fast access. Falls back to initials in the UI when null.
 */
class UploadEmployeeProfilePictureAction
{
    public function __construct(private StoreEmployeeDocumentAction $storeDocument) {}

    public function handle(Employee $employee, UploadedFile $file, Admin $actor): Employee
    {
        return DB::transaction(function () use ($employee, $file, $actor) {
            $category = EmployeeDocumentCategory::query()->where('name', 'Profile Picture')->first();

            $document = $this->storeDocument->handle($employee, $file, [
                'employee_document_category_id' => $category?->id,
            ], $actor);

            $employee->update(['profile_picture_document_id' => $document->id]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Profile picture updated for employee '{$employee->full_name}' ({$employee->employee_number}).",
                entityType: Employee::class,
                entityId: $employee->id,
            ));

            return $employee->fresh(['profilePictureDocument']);
        });
    }
}
