<?php

namespace App\Http\Requests\Api\V1\Admin\Settings;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class RestoreBackupRequest extends SettingsFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', 'in:RESTORE'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error(
            'Restore requires confirmation: send confirmation = "RESTORE".',
            'BACKUP_RESTORE_NOT_CONFIRMED',
            422,
            $validator->errors(),
        ));
    }
}
