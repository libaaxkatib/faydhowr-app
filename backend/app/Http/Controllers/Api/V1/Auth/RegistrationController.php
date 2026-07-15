<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RegisterCustomerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\AuthenticatedUserResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Throwable;

class RegistrationController extends Controller
{
    public function store(RegisterRequest $request, RegisterCustomerAction $registerCustomer): JsonResponse
    {
        try {
            $registration = $registerCustomer->handle($request->validated());
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('Registration failed.', 'REGISTRATION_FAILED', 500);
        }

        return ApiResponse::success(
            'Registration successful.',
            new AuthenticatedUserResource($registration),
            201,
        );
    }
}
