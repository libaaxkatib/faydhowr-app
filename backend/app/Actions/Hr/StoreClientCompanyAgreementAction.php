<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\ClientCompanyAgreement;
use Illuminate\Http\UploadedFile;

/**
 * Stores an optional company agreement. Uploading again adds a NEW row and
 * leaves the earlier files as history (the newest row is the current
 * agreement). The disk comes from config('filesystems.hr_attachments_disk'),
 * so production can point it at S3-compatible storage with an env change only;
 * the disk used is recorded per row so old files stay reachable.
 */
class StoreClientCompanyAgreementAction
{
    public function handle(ClientCompany $company, UploadedFile $file, ?string $notes, Admin $actor): ClientCompanyAgreement
    {
        $disk = (string) config('filesystems.hr_attachments_disk', 'local');
        $path = $file->store('client-companies/'.$company->id.'/agreements', $disk);

        $agreement = ClientCompanyAgreement::query()->create([
            'client_company_id' => $company->id,
            'uploaded_by' => $actor->id,
            'file_name' => $file->getClientOriginalName(),
            'file_type' => $file->getMimeType() ?? 'application/octet-stream',
            'file_size' => $file->getSize() ?: 0,
            'disk' => $disk,
            'file_path' => $path,
            'notes' => $notes,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Agreement uploaded for client company '{$company->name}'.",
            entityType: ClientCompanyAgreement::class,
            entityId: $agreement->id,
            metadata: ['client_company_id' => $company->id, 'file_name' => $agreement->file_name],
        ));

        return $agreement;
    }
}
