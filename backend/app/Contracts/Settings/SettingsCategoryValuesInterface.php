<?php

namespace App\Contracts\Settings;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A typed, immutable view over the values of one settings category.
 * Implementations hydrate from a map of key segment to raw JSON value and
 * serialize back to fully-qualified dotted keys, masking sensitive values.
 *
 * @extends Arrayable<string, mixed>
 */
interface SettingsCategoryValuesInterface extends Arrayable
{
    /**
     * @param  array<string, mixed>  $values  Map of key segment to raw value.
     */
    public static function fromValues(array $values): static;

    /**
     * @return array<string, mixed> Map of fully-qualified dotted key to value.
     */
    public function toArray(): array;
}
