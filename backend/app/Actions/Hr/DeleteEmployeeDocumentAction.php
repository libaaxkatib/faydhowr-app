<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Support\Facades\Storage;

class DeleteEmployeeDocumentAction
{
    public function handle(Employee $employee, EmployeeDocument $document, Admin $actor): void
    {
        abort_unless((int) $document->employee_id === (int) $employee->id, 404);

        $path = $document->file_path;
        $fileName = $document->file_name;
        $documentId = $document->id;

        $document->delete();

        if ($path !== '' && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }

        event(AuditEvent::record(
            action: AuditAction::Delete,
            admin: $actor,
            description: "Document deleted for employee '{$employee->full_name}'.",
            entityType: EmployeeDocument::class,
            entityId: $documentId,
            metadata: ['employee_id' => $employee->id, 'file_name' => $fileName],
        ));
    }
}
