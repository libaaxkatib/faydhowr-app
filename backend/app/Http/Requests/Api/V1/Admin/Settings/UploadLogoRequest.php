<?php

namespace App\Http\Requests\Api\V1\Admin\Settings;

use Illuminate\Http\UploadedFile;

class UploadLogoRequest extends SettingsFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'logo' => ['required', 'file', 'mimes:png,svg', 'max:2048'],
        ];
    }

    public function logoFile(): UploadedFile
    {
        return $this->validated('logo');
    }
}
