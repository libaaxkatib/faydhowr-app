<?php

namespace App\Http\Requests\Api\V1\Uploads;

use App\Enums\Upload\UploadMediaType;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;

class StoreUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $maxFiles = (int) config('uploads.max_files_per_request');

        return [
            'files' => ['required', 'array', 'min:1', "max:{$maxFiles}"],
            'files.*' => ['required', 'file'],
        ];
    }

    /**
     * Allow-list and per-type size caps (API Design §14.2 / §14.5).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->uploadedFiles() as $index => $file) {
                $this->validateFile($validator, $index, $file);
            }
        });
    }

    /**
     * @return list<UploadedFile>
     */
    public function uploadedFiles(): array
    {
        $files = $this->file('files');

        return is_array($files) ? array_values($files) : [];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error(
            'The given data was invalid.',
            'VALIDATION_ERROR',
            422,
            $validator->errors(),
        ));
    }

    /**
     * The extension allow-list is authoritative for the media type; the
     * client-declared MIME is cross-checked only when it maps to a known
     * media type (content sniffing is deferred per API Design §14.6).
     */
    private function validateFile(Validator $validator, int $index, UploadedFile $file): void
    {
        $mediaType = UploadMediaType::fromExtension((string) $file->getClientOriginalExtension());

        if ($mediaType === null) {
            $validator->errors()->add(
                "files.{$index}",
                'Unsupported file type. Allowed: images (JPG/JPEG/PNG/WebP), videos (MP4/MOV/WebM), PDF.',
            );

            return;
        }

        $mimeMediaType = UploadMediaType::fromMime((string) $file->getClientMimeType());

        if ($mimeMediaType !== null && $mimeMediaType !== $mediaType) {
            $validator->errors()->add(
                "files.{$index}",
                'File extension does not match its content type.',
            );

            return;
        }

        if ((int) $file->getSize() > $mediaType->maxFileBytes()) {
            $maxMegabytes = (int) round($mediaType->maxFileBytes() / (1024 * 1024));

            $validator->errors()->add(
                "files.{$index}",
                "The file exceeds the maximum size of {$maxMegabytes} MB for {$mediaType->value} uploads.",
            );
        }
    }
}
