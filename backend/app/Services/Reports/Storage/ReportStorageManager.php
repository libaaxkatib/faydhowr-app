<?php

namespace App\Services\Reports\Storage;

use App\Contracts\Reports\Storage\ReportStorageInterface;
use InvalidArgumentException;

class ReportStorageManager
{
    public const DEFAULT_DRIVER = 'local';

    /**
     * @var array<string, ReportStorageInterface>
     */
    private array $drivers = [];

    public function register(string $name, ReportStorageInterface $driver): void
    {
        $this->drivers[$name] = $driver;
    }

    public function driver(?string $name = null): ReportStorageInterface
    {
        $name ??= self::DEFAULT_DRIVER;

        return $this->drivers[$name]
            ?? throw new InvalidArgumentException("Report storage driver [{$name}] is not registered.");
    }

    /**
     * @return list<string>
     */
    public function registeredDrivers(): array
    {
        return array_keys($this->drivers);
    }
}
