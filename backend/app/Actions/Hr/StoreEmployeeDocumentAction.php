<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\UploadedFile;

class StoreEmployeeDocumentAction
{
    public function handle(Employee $employee, UploadedFile $file, Admin $actor): EmployeeDocument
    {
        $directory = 'employees/'.$employee->id.'/documents';
        $path = $file->store($directory, 'local');

        $document = EmployeeDocument::query()->create([
            'employee_id' => $employee->id,
            'admin_id' => $actor->id,
            'file_name' => $file->getClientOriginalName(),
            'file_type' => $this->classify($file->getMimeType()),
            'file_size' => $file->getSize() ?: 0,
            'file_path' => $path,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Document uploaded for employee '{$employee->full_name}'.",
            entityType: EmployeeDocument::class,
            entityId: $document->id,
            metadata: ['employee_id' => $employee->id, 'file_name' => $document->file_name],
        ));

        return $document->load('admin');
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
