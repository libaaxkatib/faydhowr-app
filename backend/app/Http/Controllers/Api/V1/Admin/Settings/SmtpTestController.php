<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Contracts\Settings\Services\SettingsServiceInterface;
use App\Exceptions\Settings\SmtpTestFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Settings\SmtpTestRequest;
use App\Models\SystemSetting;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SmtpTestController extends Controller
{
    public function __construct(private SettingsServiceInterface $settings) {}

    public function store(SmtpTestRequest $request): JsonResponse
    {
        Gate::authorize('update', SystemSetting::class);

        try {
            $this->settings->sendTestEmail($request->toEmail());
        } catch (SmtpTestFailedException $exception) {
            return ApiResponse::error($exception->getMessage(), 'SMTP_TEST_FAILED', 502);
        }

        return ApiResponse::success(
            'Test email sent successfully.',
            ['to' => $request->toEmail()],
        );
    }
}
