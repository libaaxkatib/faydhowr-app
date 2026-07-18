<?php

namespace App\Actions\Quotation;

use App\Models\CustomerProfile;
use App\Models\Upload;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Resolves staged upload UUIDs into attachable Upload rows for a customer.
 * Enforces the Sprint 28 upload-integration rules: ownership, staging expiry,
 * and single attachment. Rows are locked so a concurrent attach cannot claim
 * the same upload. Must be called inside a database transaction.
 */
class ResolveQuotationUploadsAction
{
    /**
     * @param  list<string>  $uuids
     * @return Collection<int, Upload>
     */
    public function handle(CustomerProfile $profile, array $uuids): Collection
    {
        $uploads = collect();

        foreach (array_values(array_unique($uuids)) as $uuid) {
            $upload = Upload::query()
                ->where('uuid', $uuid)
                ->where('customer_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();

            if ($upload === null || $upload->isExpired()) {
                throw new DomainException("Upload [{$uuid}] is not available for attachment.");
            }

            if ($upload->isAttached()) {
                throw new DomainException("Upload [{$uuid}] is already attached to another record.");
            }

            $uploads->push($upload);
        }

        return $uploads;
    }
}
