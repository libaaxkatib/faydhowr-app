<?php

namespace Tests\Support;

use App\Contracts\Sms\SmsSenderInterface;

class FakeSmsSender implements SmsSenderInterface
{
    /** @var list<array{phone: string, message: string}> */
    public array $sent = [];

    public function send(string $phone, string $message): void
    {
        $this->sent[] = ['phone' => $phone, 'message' => $message];
    }

    public function lastCodeFor(string $phone): ?string
    {
        foreach (array_reverse($this->sent) as $sms) {
            if ($sms['phone'] === $phone && preg_match('/\d{6}/', $sms['message'], $matches) === 1) {
                return $matches[0];
            }
        }

        return null;
    }

    public function messagesTo(string $phone): int
    {
        return count(array_filter($this->sent, fn (array $sms): bool => $sms['phone'] === $phone));
    }
}
