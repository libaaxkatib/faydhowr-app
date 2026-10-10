<?php

namespace App\Support\Hr;

use App\Enums\LocationType;
use App\Models\WorkLocation;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * One place for the rule "a work location is optional, the company is not
 * lost": given an optional location and an optional company, return the company
 * to store, or refuse a contradictory combination. Used by assignments and
 * workforce requests so both behave identically.
 */
final class CompanyLocationResolver
{
    public static function resolve(?WorkLocation $location, mixed $companyId): ?int
    {
        $companyId = $companyId !== null && $companyId !== '' ? (int) $companyId : null;

        if ($location === null) {
            if ($companyId === null) {
                self::fail('A client company or a work location is required.', 'COMPANY_OR_LOCATION_REQUIRED');
            }

            return $companyId;
        }

        if ($location->location_type === LocationType::Office) {
            if ($companyId !== null) {
                self::fail('The Fayadhowr Office is not tied to a client company.', 'OFFICE_ASSIGNMENT_HAS_NO_COMPANY');
            }

            return null;
        }

        if ($companyId !== null && $companyId !== (int) $location->client_company_id) {
            self::fail('The selected work location belongs to a different company.', 'LOCATION_COMPANY_MISMATCH');
        }

        return (int) $location->client_company_id;
    }

    /**
     * Validates completing a missing location later: it must be a client
     * location of the record's own company.
     */
    public static function assertCompletes(WorkLocation $location, ?int $recordCompanyId): void
    {
        if ($location->location_type !== LocationType::Client || (int) $location->client_company_id !== (int) $recordCompanyId) {
            self::fail('The selected work location does not belong to this record\'s company.', 'LOCATION_COMPANY_MISMATCH');
        }
    }

    private static function fail(string $message, string $code): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422));
    }
}
