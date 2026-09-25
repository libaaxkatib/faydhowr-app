<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeDocumentVerificationStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\EmployeeDocument;
use Illuminate\Support\Facades\Date;

class VerifyEmployeeDocumentAction
{
    public function handle(EmployeeDocument $document, Admin $actor): EmployeeDocument
    {
        $document->update([
            'verification_status' => EmployeeDocumentVerificationStatus::Verified,
            'verified_at' => Date::now(),
            'verified_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Document '{$document->file_name}' verified.",
            entityType: EmployeeDocument::class,
            entityId: $document->id,
            metadata: ['employee_id' => $document->employee_id],
        ));

        return $document->fresh(['admin', 'category', 'verifiedBy']);
    }
}
