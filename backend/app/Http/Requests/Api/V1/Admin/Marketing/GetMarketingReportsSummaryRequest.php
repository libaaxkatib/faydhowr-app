<?php

namespace App\Http\Requests\Api\V1\Admin\Marketing;

use App\Enums\Marketing\MarketingRecordStatus;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class GetMarketingReportsSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'assigned_team_id' => ['sometimes', 'integer', 'exists:marketing_teams,id'],
            'assigned_admin_id' => ['sometimes', 'integer', 'exists:admins,id'],
            'status' => ['sometimes', Rule::in(MarketingRecordStatus::values())],
            'type' => ['sometimes', Rule::in(['xarun', 'project'])],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
