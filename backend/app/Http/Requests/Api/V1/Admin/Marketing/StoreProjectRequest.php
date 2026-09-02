<?php

namespace App\Http\Requests\Api\V1\Admin\Marketing;

use App\Enums\Marketing\ResponsiblePartyType;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * PROJECT-only fields per docs/HRM_MARKETING_SRS.md §9-10 — a separate form
 * from XARUN. company_name is required ONLY when responsible_party_type is
 * 'company' — the SRS's explicit critical rule that company is never
 * mandatory for every project (an Engineer/Owner/Other may have no company
 * at all).
 */
class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'responsible_party_type' => ['required', Rule::in(ResponsiblePartyType::values())],
            'company_name' => ['required_if:responsible_party_type,company', 'nullable', 'string', 'max:200'],
            'responsible_person_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'location' => ['nullable', 'string', 'max:150'],
            'project_type' => ['nullable', 'string', 'max:150'],
            'project_size' => ['nullable', 'string', 'max:100'],
            'construction_completion_date' => ['nullable', 'date'],
            'fayadhowr_work_date' => ['nullable', 'date'],
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
