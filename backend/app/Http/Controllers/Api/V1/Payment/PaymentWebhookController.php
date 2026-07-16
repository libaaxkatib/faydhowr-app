<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Actions\Payment\HandlePaymentWebhookAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payment\PaymentWebhookRequest;
use App\Http\Resources\Api\V1\Payment\PaymentResource;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Throwable;

class PaymentWebhookController extends Controller
{
    public function __invoke(
        PaymentWebhookRequest $request,
        HandlePaymentWebhookAction $handlePaymentWebhook,
    ): JsonResponse {
        try {
            $payment = $handlePaymentWebhook->handle(
                $request->validated(),
                $request->header('X-Payment-Signature'),
                $request->getContent(),
            );
        } catch (InvalidArgumentException) {
            return ApiResponse::error(
                'Invalid webhook signature.',
                'INVALID_WEBHOOK_SIGNATURE',
                401,
            );
        } catch (ModelNotFoundException) {
            return ApiResponse::error(
                'Payment not found.',
                'PAYMENT_NOT_FOUND',
                404,
            );
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to process payment webhook.',
                'PAYMENT_WEBHOOK_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Payment webhook processed successfully.',
            new PaymentResource($payment),
        );
    }
}
