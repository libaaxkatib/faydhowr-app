<?php

namespace App\Http\Requests\Api\V1\Admin\Marketing;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * XARUN-only fields per docs/HRM_MARKETING_SRS.md §7-8 — a separate form
 * from PROJECT, never a shared generic one.
 */
class StoreXarunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'facility_name' => ['required', 'string', 'max:200'],
            'manager_name' => ['nullable', 'string', 'max:150'],
            'manager_title' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'location' => ['nullable', 'string', 'max:150'],
            'needs' => ['nullable', 'array'],
            'needs.*' => ['string', 'max:100'],
            'description' => ['nullable', 'string'],
            'assigned_team_id' => ['nullable', 'integer', 'exists:marketing_teams,id'],
            'assigned_admin_id' => ['nullable', 'integer', 'exists:admins,id'],
            'brought_by_admin_id' => ['nullable', 'integer', 'exists:admins,id'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
