<?php

namespace App\DataTransferObjects\Settings;

use App\Contracts\Settings\SettingsCategoryValuesInterface;
use App\Support\Settings\SettingsRegistry;

/**
 * The SMTP password is sensitive: the DTO tracks only whether one is stored
 * and serializes a mask so read APIs never expose the real value.
 */
final readonly class SmtpSettingsData implements SettingsCategoryValuesInterface
{
    public function __construct(
        public ?string $host,
        public ?int $port,
        public ?string $encryption,
        public ?string $username,
        public bool $hasPassword,
    ) {}

    public static function fromValues(array $values): static
    {
        return new self(
            host: $values['host'] ?? null,
            port: isset($values['port']) ? (int) $values['port'] : null,
            encryption: $values['encryption'] ?? null,
            username: $values['username'] ?? null,
            hasPassword: isset($values['password']) && $values['password'] !== null && $values['password'] !== '',
        );
    }

    public function toArray(): array
    {
        return [
            'smtp.host' => $this->host,
            'smtp.port' => $this->port,
            'smtp.encryption' => $this->encryption,
            'smtp.username' => $this->username,
            'smtp.password' => $this->hasPassword ? SettingsRegistry::mask() : null,
        ];
    }
}
