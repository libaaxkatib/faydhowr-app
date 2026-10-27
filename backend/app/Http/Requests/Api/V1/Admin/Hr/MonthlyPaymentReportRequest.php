<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Models\ClientCompany;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * The month, and what to include: `scope` = all (default), company (one Company) or guri (one Guri). A scoped request names the record by its id,
 * which must exist AND be of the stated kind - a Guri id sent as a Company (or the reverse) is refused, never silently accepted. `client_company_id`
 * without a scope keeps its earlier meaning (one client record of either kind).
 */
class MonthlyPaymentReportRequest extends FormRequest
{
    public const SCOPES = ['all', 'company', 'guri'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'scope' => ['sometimes', 'nullable', Rule::in(self::SCOPES)],
            // A list for one company or guri only (omit it, or send scope=all, for the all-records list).
            'client_company_id' => ['sometimes', 'nullable', 'integer', 'exists:client_companies,id'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $scope = $this->input('scope');

            if (in_array($scope, ['company', 'guri'], true)) {
                if ($this->input('client_company_id') === null || $this->input('client_company_id') === '') {
                    $validator->errors()->add('client_company_id', 'Choose the '.$scope.' to download.');

                    return;
                }

                $category = ClientCompany::query()->whereKey((int) $this->input('client_company_id'))->value('category');
                $actual = $category instanceof \BackedEnum ? $category->value : $category;

                if ($actual !== null && $actual !== $scope) {
                    $validator->errors()->add('client_company_id', 'The selected record is a '.($actual === 'guri' ? 'Guri' : 'Company').', not a '.($scope === 'guri' ? 'Guri' : 'Company').'.');
                }
            }
        }];
    }

    /** The record to filter by, or null for the all-records list. */
    public function filterCompanyId(): ?int
    {
        if ($this->validated('scope') === 'all') {
            return null;
        }

        $id = $this->validated('client_company_id');

        return $id === null ? null : (int) $id;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
