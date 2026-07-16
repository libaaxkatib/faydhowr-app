<?php

namespace App\Actions\Payment;

use App\Enums\PaymentStatus;
use App\Models\CustomerProfile;
use App\Models\Payment;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class ProcessPaymentAction
{
    public function handle(CustomerProfile $profile, int $paymentId): Payment
    {
        return DB::transaction(function () use ($profile, $paymentId): Payment {
            $profile = CustomerProfile::query()
                ->whereKey($profile)
                ->lockForUpdate()
                ->firstOrFail();

            $payment = $profile->payments()
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                throw new ModelNotFoundException;
            }

            if ($payment->status !== PaymentStatus::Initialized) {
                throw new DomainException('Only initialized payments can be processed.');
            }

            $transaction = $payment->transactions()
                ->where('status', PaymentStatus::Initialized->value)
                ->latest('id')
                ->lockForUpdate()
                ->firstOrFail();

            $payment->update([
                'status' => PaymentStatus::Processing,
            ]);
            $transaction->update([
                'status' => PaymentStatus::Processing->value,
                'processed_at' => now(),
            ]);
            $payment->statusHistories()->create([
                'status' => PaymentStatus::Processing,
                'changed_by_type' => 'user',
                'changed_by_id' => $profile->user_id,
                'notes' => null,
            ]);

            return $payment->load(['payable', 'transactions', 'statusHistories']);
        });
    }
}
