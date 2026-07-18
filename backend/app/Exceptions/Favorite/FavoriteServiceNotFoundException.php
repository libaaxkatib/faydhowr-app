<?php

namespace App\Exceptions\Favorite;

use RuntimeException;

/**
 * The target service does not exist or is not accessible (inactive /
 * deleted) to the customer — mapped to 404 NOT_FOUND (API Design §16.4C).
 */
class FavoriteServiceNotFoundException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Service not found.');
    }
}
