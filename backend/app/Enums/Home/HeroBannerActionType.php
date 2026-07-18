<?php

namespace App\Enums\Home;

enum HeroBannerActionType: string
{
    case Service = 'service';
    case Product = 'product';
    case Category = 'category';
    case Url = 'url';
    case None = 'none';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
