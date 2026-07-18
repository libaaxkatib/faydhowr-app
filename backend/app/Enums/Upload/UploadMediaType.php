<?php

namespace App\Enums\Upload;

enum UploadMediaType: string
{
    case Image = 'image';
    case Video = 'video';
    case Document = 'document';

    public static function fromMime(string $mimeType): ?self
    {
        foreach (self::cases() as $case) {
            if (in_array(strtolower($mimeType), $case->mimeTypes(), true)) {
                return $case;
            }
        }

        return null;
    }

    public static function fromExtension(string $extension): ?self
    {
        foreach (self::cases() as $case) {
            if (in_array(strtolower($extension), $case->extensions(), true)) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Allow-list per API Design §14.2 (frozen).
     *
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        return match ($this) {
            self::Image => ['image/jpeg', 'image/png', 'image/webp'],
            self::Video => ['video/mp4', 'video/quicktime', 'video/webm'],
            self::Document => ['application/pdf'],
        };
    }

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return match ($this) {
            self::Image => ['jpg', 'jpeg', 'png', 'webp'],
            self::Video => ['mp4', 'mov', 'webm'],
            self::Document => ['pdf'],
        };
    }

    public function maxFileBytes(): int
    {
        return (int) config('uploads.max_file_bytes.'.$this->value);
    }
}
