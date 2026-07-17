<?php

namespace App\Http\Requests\Api\V1\Admin\Settings;

class SmtpTestRequest extends SettingsFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to_email' => ['required', 'email'],
        ];
    }

    public function toEmail(): string
    {
        return $this->validated('to_email');
    }
}
