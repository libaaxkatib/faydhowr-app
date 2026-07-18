<?php

namespace App\Support\Catalog;

use App\Models\Service;
use App\Models\ServiceMedia;
use Illuminate\Support\Str;

/**
 * Builds the documented images contract (API §6): the primary media supplies
 * thumbnail and hero_image; remaining media form gallery[] in sort order.
 * All URLs are returned absolute.
 */
final class ServiceImages
{
    /**
     * @return array{thumbnail: ?string, hero_image: ?string, gallery: list<string>}
     */
    public static function for(Service $service): array
    {
        $media = $service->media;

        $primary = $media->firstWhere('is_primary', true) ?? $media->first();

        $gallery = $media
            ->reject(fn (ServiceMedia $item): bool => $primary !== null && $item->is($primary))
            ->map(fn (ServiceMedia $item): string => self::absoluteUrl($item->url))
            ->values()
            ->all();

        $primaryUrl = $primary !== null ? self::absoluteUrl($primary->url) : null;

        return [
            'thumbnail' => $primaryUrl,
            'hero_image' => $primaryUrl,
            'gallery' => $gallery,
        ];
    }

    private static function absoluteUrl(string $url): string
    {
        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        return url($url);
    }
}
