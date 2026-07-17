<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Contracts\Settings\Services\SettingsServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Settings\UploadLogoRequest;
use App\Models\SystemSetting;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CompanyLogoController extends Controller
{
    public function __construct(private SettingsServiceInterface $settings) {}

    public function store(UploadLogoRequest $request): JsonResponse
    {
        Gate::authorize('update', SystemSetting::class);

        $url = $this->settings->storeCompanyLogo(
            $request->logoFile(),
            $request->user(),
            $request->ip(),
        );

        return ApiResponse::success(
            'Logo uploaded successfully.',
            ['company.logo' => $url],
        );
    }
}
