<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Contracts\Settings\Services\SettingsServiceInterface;
use App\DataTransferObjects\Settings\SettingsCategoryData;
use App\Enums\Settings\SettingCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Settings\UpdateSettingsRequest;
use App\Models\SystemSetting;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SettingsController extends Controller
{
    public function __construct(private SettingsServiceInterface $settings) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', SystemSetting::class);

        return ApiResponse::success(
            'Settings retrieved successfully.',
            array_map(
                fn (SettingsCategoryData $category): array => $category->toArray(),
                $this->settings->allSettings(),
            ),
        );
    }

    public function show(string $category): JsonResponse
    {
        Gate::authorize('viewAny', SystemSetting::class);

        $settingCategory = $this->resolveCategory($category);

        if ($settingCategory === null) {
            return $this->categoryNotFound($category);
        }

        return ApiResponse::success(
            'Settings retrieved successfully.',
            $this->settings->categorySettings($settingCategory)->toArray(),
        );
    }

    public function update(UpdateSettingsRequest $request, string $category): JsonResponse
    {
        Gate::authorize('update', SystemSetting::class);

        $settingCategory = $this->resolveCategory($category);

        if ($settingCategory === null) {
            return $this->categoryNotFound($category);
        }

        $data = $this->settings->updateCategory(
            $settingCategory,
            $request->settingsValues(),
            $request->user(),
            $request->ip(),
        );

        return ApiResponse::success(
            sprintf('%s settings updated successfully.', $settingCategory->label()),
            $data->toArray(),
        );
    }

    public function restoreDefaults(Request $request, string $category): JsonResponse
    {
        Gate::authorize('update', SystemSetting::class);

        $settingCategory = $this->resolveCategory($category);

        if ($settingCategory === null) {
            return $this->categoryNotFound($category);
        }

        $data = $this->settings->restoreDefaults(
            $settingCategory,
            $request->user(),
            $request->ip(),
        );

        return ApiResponse::success(
            sprintf('%s settings restored to defaults successfully.', $settingCategory->label()),
            $data->toArray(),
        );
    }

    /**
     * The branch category is managed through the Branch endpoints, so it is
     * not addressable through the per-category settings routes.
     */
    private function resolveCategory(string $category): ?SettingCategory
    {
        $settingCategory = SettingCategory::tryFrom($category);

        return $settingCategory === SettingCategory::Branch ? null : $settingCategory;
    }

    private function categoryNotFound(string $category): JsonResponse
    {
        return ApiResponse::error(
            sprintf('Unknown settings category "%s".', $category),
            'SETTINGS_CATEGORY_NOT_FOUND',
            404,
        );
    }
}
