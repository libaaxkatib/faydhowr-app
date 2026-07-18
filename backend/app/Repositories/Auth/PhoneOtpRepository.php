<?php

namespace App\Repositories\Auth;

use App\Contracts\Auth\Repositories\PhoneOtpRepositoryInterface;
use App\Enums\Auth\OtpPurpose;
use App\Models\PhoneOtp;
use Illuminate\Database\Eloquent\Collection;

class PhoneOtpRepository implements PhoneOtpRepositoryInterface
{
    public function recentTerminal(string $phone, OtpPurpose $purpose, \DateTimeInterface $since): Collection
    {
        return PhoneOtp::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose->value)
            ->where('created_at', '>=', $since)
            ->where(function ($query): void {
                $query->whereNotNull('consumed_at')->orWhereNotNull('invalidated_at');
            })
            ->get();
    }

    public function findActive(string $phone, OtpPurpose $purpose): ?PhoneOtp
    {
        return PhoneOtp::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();
    }

    public function latestFor(string $phone, OtpPurpose $purpose): ?PhoneOtp
    {
        return PhoneOtp::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose->value)
            ->latest('created_at')
            ->first();
    }

    public function countIssuedSince(string $phone, OtpPurpose $purpose, \DateTimeInterface $since): int
    {
        return PhoneOtp::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose->value)
            ->where('created_at', '>=', $since)
            ->count();
    }

    public function invalidateActive(string $phone, OtpPurpose $purpose): void
    {
        PhoneOtp::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->update(['invalidated_at' => now()]);
    }

    public function create(string $phone, OtpPurpose $purpose, string $otpHash, \DateTimeInterface $expiresAt): PhoneOtp
    {
        return PhoneOtp::query()->create([
            'phone' => $phone,
            'purpose' => $purpose,
            'otp_hash' => $otpHash,
            'attempts' => 0,
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);
    }

    public function incrementAttempts(PhoneOtp $otp): PhoneOtp
    {
        $otp->attempts++;
        $otp->save();

        return $otp;
    }

    public function invalidate(PhoneOtp $otp): PhoneOtp
    {
        $otp->invalidated_at = now();
        $otp->save();

        return $otp;
    }

    public function markConsumed(PhoneOtp $otp): PhoneOtp
    {
        $otp->consumed_at = now();
        $otp->save();

        return $otp;
    }
}
