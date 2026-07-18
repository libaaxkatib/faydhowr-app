<?php

namespace App\Enums\Customer;

enum AttachmentType: string
{
    case Image = 'image';
    case Pdf = 'pdf';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Image',
            self::Pdf => 'PDF',
            self::Document => 'Document',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function fromMime(?string $mime): self
    {
        $mime = strtolower((string) $mime);

        if (str_starts_with($mime, 'image/')) {
            return self::Image;
        }

        if ($mime === 'application/pdf') {
            return self::Pdf;
        }

        return self::Document;
    }
}
