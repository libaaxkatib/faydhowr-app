<?php

namespace App\Contracts\Auth\Repositories;

use App\Enums\Auth\OtpPurpose;
use App\Models\PhoneOtp;
use Illuminate\Database\Eloquent\Collection;

interface PhoneOtpRepositoryInterface
{
    public function findActive(string $phone, OtpPurpose $purpose): ?PhoneOtp;

    /**
     * Terminal (consumed or invalidated) OTPs issued since the given moment.
     *
     * @return Collection<int, PhoneOtp>
     */
    public function recentTerminal(string $phone, OtpPurpose $purpose, \DateTimeInterface $since): Collection;

    public function latestFor(string $phone, OtpPurpose $purpose): ?PhoneOtp;

    public function countIssuedSince(string $phone, OtpPurpose $purpose, \DateTimeInterface $since): int;

    public function invalidateActive(string $phone, OtpPurpose $purpose): void;

    public function create(string $phone, OtpPurpose $purpose, string $otpHash, \DateTimeInterface $expiresAt): PhoneOtp;

    public function incrementAttempts(PhoneOtp $otp): PhoneOtp;

    public function invalidate(PhoneOtp $otp): PhoneOtp;

    public function markConsumed(PhoneOtp $otp): PhoneOtp;
}
