<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes a document record and its file on the disk the record names. A recovery mirror is a separate disk and is never
 * touched here, so deleting a document does not silently delete its recoverable copy. A record whose file is already gone
 * can still be deleted.
 */
class DeleteEmployeeDocumentAction
{
    public function handle(Employee $employee, EmployeeDocument $document, Admin $actor): void
    {
        abort_unless((int) $document->employee_id === (int) $employee->id, 404);

        $path = $document->file_path;
        $disk = $document->disk ?: 'local';
        $fileName = $document->file_name;
        $documentId = $document->id;

        $document->delete();

        $this->removeFile($disk, $path, $documentId);

        event(AuditEvent::record(
            action: AuditAction::Delete,
            admin: $actor,
            description: "Document deleted for employee '{$employee->full_name}'.",
            entityType: EmployeeDocument::class,
            entityId: $documentId,
            metadata: ['employee_id' => $employee->id, 'file_name' => $fileName],
        ));
    }

    private function removeFile(string $disk, string $path, int $documentId): void
    {
        if ($path === '') {
            return;
        }

        try {
            $driver = Storage::disk($disk)->getDriver();

            if ($driver->fileExists($path)) {
                $driver->delete($path);
            }
        } catch (Throwable) {
            // The record is gone; a file that could not be removed becomes an unreferenced file the inventory command reports.
            Log::warning('A deleted employee document\'s file could not be removed from storage.', ['document_id' => $documentId, 'reason' => 'delete_failed']);
        }
    }
}
