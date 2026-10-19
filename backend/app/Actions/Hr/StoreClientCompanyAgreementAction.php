<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\ClientCompanyAgreement;
use App\Support\ApiResponse;
use App\Support\Hr\Documents\ClientCompanyAgreementStorage;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores an optional company agreement. Uploading again adds a NEW row and leaves the earlier files as history (the
 * newest row is the current agreement).
 *
 * The file is written to the configured private disk and verified (size and SHA-256 read back) BEFORE any row exists; the
 * row records the disk and the checksum. If the row cannot be saved, the object this request wrote is removed again, so a
 * failed upload leaves neither a row nor a stray file. A storage problem (unreachable, misconfigured, not durable in
 * production) surfaces as DocumentStorageException (HTTP 503) and nothing is saved.
 *
 * Uploads are refused outright (HTTP 403, AGREEMENT_UPLOADS_DISABLED) unless filesystems.hr_agreement_uploads_enabled is on;
 * it stays off until the mirror and a restore drill have passed. Nothing else about agreements is affected.
 */
class StoreClientCompanyAgreementAction
{
    public function __construct(private readonly ClientCompanyAgreementStorage $storage) {}

    public function handle(ClientCompany $company, UploadedFile $file, ?string $notes, Admin $actor): ClientCompanyAgreement
    {
        if (! config('filesystems.hr_agreement_uploads_enabled', false)) {
            throw new HttpResponseException(ApiResponse::error(
                'Agreement uploads are switched off until private storage, the verified mirror and a restore test have all passed. Nothing was saved.',
                'AGREEMENT_UPLOADS_DISABLED',
                403,
            ));
        }

        $stored = $this->storage->store($file, $company->id);

        try {
            return DB::transaction(function () use ($company, $file, $notes, $actor, $stored): ClientCompanyAgreement {
                $agreement = ClientCompanyAgreement::query()->create([
                    'client_company_id' => $company->id,
                    'uploaded_by' => $actor->id,
                    'file_name' => $this->displayName($file),
                    'file_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'file_size' => $stored->size,
                    'disk' => $stored->disk,
                    'file_path' => $stored->path,
                    'sha256' => $stored->sha256,
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
            });
        } catch (Throwable $e) {
            $this->storage->discard($stored);

            throw $e;
        }
    }

    /** The name shown to people: no folders, no control characters, at most 255 characters. */
    private function displayName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim(Str::limit($name, 255, ''));

        return $name === '' ? 'agreement' : $name;
    }
}
