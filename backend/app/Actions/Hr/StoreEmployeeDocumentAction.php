<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeDocumentVerificationStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * docs/HRM_MARKETING_SRS.md HR §27/§39: uploading into a category that
 * already has a current document supersedes it (is_current flips false,
 * superseded_by_document_id points at the new row) instead of the old
 * hard-delete-on-replace behaviour — the confirmed fix for the audit's
 * document-history conflict. A category-less upload (legacy callers) simply
 * never supersedes anything, matching the pre-Phase-1 behaviour.
 */
class StoreEmployeeDocumentAction
{
    public function handle(Employee $employee, UploadedFile $file, array $data, Admin $actor): EmployeeDocument
    {
        return DB::transaction(function () use ($employee, $file, $data, $actor) {
            $categoryId = $data['employee_document_category_id'] ?? null;

            $previousCurrent = $categoryId
                ? $employee->documents()
                    ->where('employee_document_category_id', $categoryId)
                    ->where('is_current', true)
                    ->first()
                : null;

            $directory = 'employees/'.$employee->id.'/documents';
            $path = $file->store($directory, 'local');

            $document = EmployeeDocument::query()->create([
                'employee_id' => $employee->id,
                'admin_id' => $actor->id,
                'employee_document_category_id' => $categoryId,
                'file_name' => $file->getClientOriginalName(),
                'file_type' => $this->classify($file->getMimeType()),
                'file_size' => $file->getSize() ?: 0,
                'file_path' => $path,
                'document_number' => $data['document_number'] ?? null,
                'verification_status' => EmployeeDocumentVerificationStatus::Pending,
                'expiry_date' => $data['expiry_date'] ?? null,
                'is_current' => true,
            ]);

            if ($previousCurrent) {
                $previousCurrent->update([
                    'is_current' => false,
                    'superseded_by_document_id' => $document->id,
                ]);
            }

            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: "Document uploaded for employee '{$employee->full_name}'.",
                entityType: EmployeeDocument::class,
                entityId: $document->id,
                metadata: [
                    'employee_id' => $employee->id,
                    'file_name' => $document->file_name,
                    'superseded_document_id' => $previousCurrent?->id,
                ],
            ));

            return $document->load('admin', 'category');
        });
    }

    private function classify(?string $mime): string
    {
        $mime = strtolower((string) $mime);

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if ($mime === 'application/pdf') {
            return 'pdf';
        }

        return 'document';
    }
}
