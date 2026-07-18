<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsSenderInterface;
use InvalidArgumentException;

/**
 * Provider-independent SMS delivery (SRS FR-002E). Concrete providers are
 * registered by name and selected through configuration only; swapping
 * providers never requires changes to authentication business logic.
 */
class SmsSenderManager
{
    /** @var array<string, SmsSenderInterface> */
    private array $drivers = [];

    public function register(string $name, SmsSenderInterface $driver): void
    {
        $this->drivers[$name] = $driver;
    }

    public function driver(?string $name = null): SmsSenderInterface
    {
        $name ??= (string) config('services.sms.driver', 'log');

        if (! isset($this->drivers[$name])) {
            throw new InvalidArgumentException("SMS driver [{$name}] is not registered.");
        }

        return $this->drivers[$name];
    }
}
