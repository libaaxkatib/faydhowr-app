<?php

namespace App\Http\Requests\Api\V1\Admin\Settings;

use App\Enums\Settings\SettingCategory;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Contracts\Validation\Validator;

class UpdateSettingsRequest extends SettingsFormRequest
{
    /**
     * Payload keys are fully-qualified dotted keys (e.g. "tax.rate"), which
     * clash with Laravel's nested validation syntax; they are re-keyed by
     * segment under a "settings" container before validation.
     */
    protected function prepareForValidation(): void
    {
        $category = $this->settingCategory();

        if ($category === null) {
            return;
        }

        $prefix = $category->value.'.';
        $settings = [];

        foreach ($this->json()->all() as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $settings[substr($key, strlen($prefix))] = $value;
            }
        }

        $this->merge(['settings' => $settings]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = $this->settingCategory();

        if ($category === null) {
            return [];
        }

        $rules = ['settings' => ['required', 'array', 'min:1']];

        foreach ($this->rulesFor($category) as $key => $keyRules) {
            $rules['settings.'.$key] = $keyRules;
        }

        return $rules;
    }

    /**
     * Reject payload keys that are not editable keys of the category.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        $category = $this->settingCategory();

        if ($category === null) {
            return [];
        }

        return [
            function (Validator $validator) use ($category): void {
                $known = array_map(
                    fn (string $key): string => $category->value.'.'.$key,
                    SettingsRegistry::editableKeysFor($category),
                );

                foreach (array_keys($this->json()->all()) as $key) {
                    if ($key !== 'settings' && ! in_array($key, $known, true)) {
                        $validator->errors()->add($key, sprintf(
                            'The key "%s" is not an editable %s setting.',
                            $key,
                            $category->value,
                        ));
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, mixed> Map of fully-qualified dotted key to new value.
     */
    public function settingsValues(): array
    {
        $category = $this->settingCategory();
        $values = [];

        foreach ($this->validated('settings', []) as $key => $value) {
            $values[$category->value.'.'.$key] = $value;
        }

        return $values;
    }

    public function settingCategory(): ?SettingCategory
    {
        $category = SettingCategory::tryFrom((string) $this->route('category'));

        return $category === SettingCategory::Branch ? null : $category;
    }

    /**
     * @return array<string, list<mixed>> Validation rules per editable key segment.
     */
    private function rulesFor(SettingCategory $category): array
    {
        return match ($category) {
            SettingCategory::Company => [
                'name' => ['sometimes', 'string', 'min:1', 'max:255'],
                'logo' => ['sometimes', 'nullable', 'string', 'max:2048'],
                'email' => ['sometimes', 'nullable', 'email', 'max:255'],
                'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
                'website' => ['sometimes', 'nullable', 'url', 'max:255'],
                'address' => ['sometimes', 'nullable', 'string', 'max:500'],
                'tax_id' => ['sometimes', 'nullable', 'string', 'max:100'],
                'business_hours_open' => ['sometimes', 'nullable', 'date_format:H:i'],
                'business_hours_close' => ['sometimes', 'nullable', 'date_format:H:i'],
                'facebook' => ['sometimes', 'nullable', 'string', 'max:255'],
                'instagram' => ['sometimes', 'nullable', 'string', 'max:255'],
                'whatsapp' => ['sometimes', 'nullable', 'string', 'max:255'],
            ],
            SettingCategory::Currency => [
                'default' => ['sometimes', 'string', 'min:2', 'max:10'],
                'symbol' => ['sometimes', 'string', 'min:1', 'max:5'],
                'decimal_places' => ['sometimes', 'integer', 'in:0,2'],
                'thousand_separator' => ['sometimes', 'nullable', 'string', 'max:1'],
            ],
            SettingCategory::Tax => [
                'default' => ['sometimes', 'boolean'],
                'rate' => ['sometimes', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
                'mode' => ['sometimes', 'in:inclusive,exclusive'],
            ],
            SettingCategory::Numbering => [
                'customer_prefix' => $this->prefixRules(),
                'booking_prefix' => $this->prefixRules(),
                'quotation_prefix' => $this->prefixRules(),
                'invoice_prefix' => $this->prefixRules(),
                'receipt_prefix' => $this->prefixRules(),
                'order_prefix' => $this->prefixRules(),
                'payment_prefix' => $this->prefixRules(),
                'auto_numbering' => ['sometimes', 'boolean'],
            ],
            SettingCategory::Smtp => [
                'host' => ['sometimes', 'nullable', 'string', 'max:255'],
                'port' => ['sometimes', 'integer', 'between:1,65535'],
                'encryption' => ['sometimes', 'in:none,ssl,tls'],
                'username' => ['sometimes', 'nullable', 'string', 'max:255'],
                'password' => ['sometimes', 'nullable', 'string', 'max:255'],
            ],
            SettingCategory::Notifications => [
                'email' => ['sometimes', 'boolean'],
                'browser' => ['sometimes', 'boolean'],
                'booking_alerts' => ['sometimes', 'boolean'],
                'quotation_alerts' => ['sometimes', 'boolean'],
                'payment_alerts' => ['sometimes', 'boolean'],
            ],
            SettingCategory::Storage => [
                'driver' => ['sometimes', 'in:local,s3'],
                'max_upload_size' => ['sometimes', 'integer', 'min:1', 'max:1048576'],
                'allowed_file_types' => ['sometimes', 'array', 'min:1'],
                'allowed_file_types.*' => ['in:jpg,jpeg,png,gif,svg,webp,pdf,doc,docx,xls,xlsx,csv,txt,zip'],
            ],
            SettingCategory::Localization => [
                'language' => ['sometimes', 'string', 'min:2', 'max:10'],
                'timezone' => ['sometimes', 'timezone:all'],
                'date_format' => ['sometimes', 'string', 'min:1', 'max:20'],
                'time_format' => ['sometimes', 'string', 'min:1', 'max:20'],
            ],
            SettingCategory::Backup => [
                'enabled' => ['sometimes', 'boolean'],
                'retention_days' => ['sometimes', 'integer', 'between:1,365'],
            ],
            SettingCategory::Branch => [],
        };
    }

    /**
     * @return list<mixed>
     */
    private function prefixRules(): array
    {
        return ['sometimes', 'string', 'min:1', 'max:10', 'regex:/^[A-Z0-9-]+$/'];
    }
}
