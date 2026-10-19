<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Corrects the category of an EXISTING document (for example one that was uploaded before a category was chosen).
 *
 * It changes one column on the existing row and nothing else: no new document row, no new or rewritten file, and the
 * stored disk, path, size, checksum, verification state and expiry are untouched (the storage is not even contacted).
 * It never supersedes anything: if the target category already holds a different current document the change is refused
 * and HR decides what to do with that one. Only the current document of an employee can be re-categorised, and the
 * employee's profile picture is left alone. The employee row is locked first so two simultaneous changes for the same
 * employee cannot both claim the same category.
 */
class UpdateEmployeeDocumentCategoryAction
{
    public function handle(Employee $employee, EmployeeDocument $document, int $categoryId, Admin $actor): EmployeeDocument
    {
        $changed = DB::transaction(function () use ($employee, $document, $categoryId): ?array {
            $lockedEmployee = Employee::withTrashed()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
            $locked = EmployeeDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->employee_id !== (int) $lockedEmployee->id) {
                $this->refuse('Document was not found.', 'EMPLOYEE_DOCUMENT_NOT_FOUND', 404);
            }

            if ((int) $lockedEmployee->profile_picture_document_id === (int) $locked->id) {
                $this->refuse('The profile picture is not a categorised document. Replace it from the profile instead.', 'DOCUMENT_IS_PROFILE_PICTURE');
            }

            if (! $locked->is_current) {
                $this->refuse('Only the current document of an employee can be moved to another category.', 'DOCUMENT_NOT_CURRENT');
            }

            if ((int) $locked->employee_document_category_id === $categoryId) {
                return null; // already there: nothing to change, nothing to record
            }

            $occupied = EmployeeDocument::query()
                ->where('employee_id', $lockedEmployee->id)
                ->where('employee_document_category_id', $categoryId)
                ->where('is_current', true)
                ->whereKeyNot($locked->id)
                ->exists();

            if ($occupied) {
                $this->refuse(
                    'That category already has a current document for this employee. Remove it or upload the new file into that category instead.',
                    'DOCUMENT_CATEGORY_OCCUPIED',
                );
            }

            $previous = $locked->employee_document_category_id;
            $locked->update(['employee_document_category_id' => $categoryId]);

            return ['previous' => $previous, 'document' => $locked];
        });

        if ($changed !== null) {
            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Document '{$changed['document']->file_name}' moved to another category.",
                entityType: EmployeeDocument::class,
                entityId: $changed['document']->id,
                metadata: [
                    'employee_id' => $employee->id,
                    'previous_category_id' => $changed['previous'],
                    'category_id' => $categoryId,
                ],
            ));
        }

        return $document->fresh(['admin', 'category', 'verifiedBy']);
    }

    private function refuse(string $message, string $code, int $status = 422): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, $status));
    }
}
