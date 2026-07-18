<?php

namespace App\Enums\Search;

enum SearchType: string
{
    case All = 'all';
    case Service = 'service';
    case Product = 'product';

    public function includesServices(): bool
    {
        return $this !== self::Product;
    }

    public function includesProducts(): bool
    {
        return $this !== self::Service;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
