<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeDocumentVerificationStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\Hr\Documents\EmployeeDocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * docs/HRM_MARKETING_SRS.md HR §27/§39: uploading into a category that
 * already has a current document supersedes it (is_current flips false,
 * superseded_by_document_id points at the new row) instead of the old
 * hard-delete-on-replace behaviour — the confirmed fix for the audit's
 * document-history conflict. A category-less upload (legacy callers) simply
 * never supersedes anything, matching the pre-Phase-1 behaviour.
 *
 * The file is written to the configured private disk and verified (size and SHA-256 read back) BEFORE any row exists; the row
 * records the disk and checksum. If anything after the write fails, the object this request created is removed again, so a
 * failed upload leaves neither a row nor a stray file. A storage failure surfaces as DocumentStorageException (HTTP 503).
 */
class StoreEmployeeDocumentAction
{
    public function __construct(private readonly EmployeeDocumentStorage $storage) {}

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

            $stored = $this->storage->store($file, $employee->id);

            try {
                $document = EmployeeDocument::query()->create([
                    'employee_id' => $employee->id,
                    'admin_id' => $actor->id,
                    'employee_document_category_id' => $categoryId,
                    'file_name' => $file->getClientOriginalName(),
                    'file_type' => $this->classify($file->getMimeType()),
                    'file_size' => $stored->size,
                    'file_path' => $stored->path,
                    'disk' => $stored->disk,
                    'sha256' => $stored->sha256,
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
            } catch (Throwable $e) {
                // The row was not saved, so the file written a moment ago would be an orphan: remove it (only what this request created).
                $this->storage->discard($stored);

                throw $e;
            }

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
