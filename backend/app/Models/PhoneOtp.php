<?php

namespace App\Models;

use App\Enums\Auth\OtpPurpose;
use Database\Factories\PhoneOtpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'phone',
    'purpose',
    'otp_hash',
    'attempts',
    'expires_at',
    'consumed_at',
    'invalidated_at',
    'created_at',
])]
class PhoneOtp extends Model
{
    /** @use HasFactory<PhoneOtpFactory> */
    use HasFactory;

    public $timestamps = false;

    public function isActive(): bool
    {
        return $this->consumed_at === null
            && $this->invalidated_at === null
            && $this->expires_at->isFuture();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
