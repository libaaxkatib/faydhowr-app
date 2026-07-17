<?php

namespace App\Http\Requests\Api\V1\Admin\Settings;

use App\Enums\Settings\SettingCategory;
use Illuminate\Validation\Rule;

class ListSettingsAuditLogsRequest extends SettingsFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['sometimes', Rule::in(SettingCategory::values())],
            'changed_by' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'limit' => ['sometimes', 'integer', 'between:1,200'],
        ];
    }

    /**
     * @return array{category?: string|null, changed_by?: int|null, from?: string|null, to?: string|null, limit?: int|null}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'category' => $validated['category'] ?? null,
            'changed_by' => isset($validated['changed_by']) ? (int) $validated['changed_by'] : null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'limit' => isset($validated['limit']) ? (int) $validated['limit'] : null,
        ];
    }
}
