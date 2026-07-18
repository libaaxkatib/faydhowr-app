<?php

namespace Tests\Unit\Uploads;

use App\Enums\Upload\UploadMediaType;
use PHPUnit\Framework\TestCase;

class UploadMediaTypeTest extends TestCase
{
    public function test_resolves_media_type_from_allowed_mime_types(): void
    {
        $this->assertSame(UploadMediaType::Image, UploadMediaType::fromMime('image/jpeg'));
        $this->assertSame(UploadMediaType::Image, UploadMediaType::fromMime('image/png'));
        $this->assertSame(UploadMediaType::Image, UploadMediaType::fromMime('image/webp'));
        $this->assertSame(UploadMediaType::Video, UploadMediaType::fromMime('video/mp4'));
        $this->assertSame(UploadMediaType::Video, UploadMediaType::fromMime('video/quicktime'));
        $this->assertSame(UploadMediaType::Video, UploadMediaType::fromMime('video/webm'));
        $this->assertSame(UploadMediaType::Document, UploadMediaType::fromMime('application/pdf'));
        $this->assertSame(UploadMediaType::Image, UploadMediaType::fromMime('IMAGE/JPEG'));
    }

    public function test_rejects_mime_types_outside_the_allow_list(): void
    {
        $this->assertNull(UploadMediaType::fromMime('image/gif'));
        $this->assertNull(UploadMediaType::fromMime('application/x-msdownload'));
        $this->assertNull(UploadMediaType::fromMime('text/html'));
        $this->assertNull(UploadMediaType::fromMime('video/x-msvideo'));
    }

    public function test_resolves_media_type_from_allowed_extensions(): void
    {
        $this->assertSame(UploadMediaType::Image, UploadMediaType::fromExtension('jpg'));
        $this->assertSame(UploadMediaType::Image, UploadMediaType::fromExtension('JPEG'));
        $this->assertSame(UploadMediaType::Video, UploadMediaType::fromExtension('mov'));
        $this->assertSame(UploadMediaType::Document, UploadMediaType::fromExtension('pdf'));
        $this->assertNull(UploadMediaType::fromExtension('exe'));
        $this->assertNull(UploadMediaType::fromExtension('gif'));
    }
}
