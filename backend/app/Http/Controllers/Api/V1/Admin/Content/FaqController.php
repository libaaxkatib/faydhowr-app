<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Actions\Home\CreateFaqAction;
use App\Actions\Home\DeleteFaqAction;
use App\Actions\Home\UpdateFaqAction;
use App\Contracts\Home\Repositories\FaqRepositoryInterface;
use App\DataTransferObjects\Home\CreateFaqData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\ListContentRequest;
use App\Http\Requests\Api\V1\Admin\Content\StoreFaqRequest;
use App\Http\Requests\Api\V1\Admin\Content\UpdateFaqRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminFaqResource;
use App\Models\Admin;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FaqController extends Controller
{
    public function __construct(private FaqRepositoryInterface $faqs) {}

    public function index(ListContentRequest $request): JsonResponse
    {
        $paginator = $this->faqs->paginateForAdmin($request->perPage());

        return ApiResponse::success(
            'FAQs retrieved successfully.',
            ['items' => AdminFaqResource::collection($paginator->items())],
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function store(StoreFaqRequest $request, CreateFaqAction $createFaq): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        $faq = $createFaq->handle(
            $admin,
            CreateFaqData::fromValidated($request->validated()),
        );

        return ApiResponse::success(
            'FAQ created successfully.',
            new AdminFaqResource($faq),
            201,
        );
    }

    public function update(UpdateFaqRequest $request, int $faq, UpdateFaqAction $updateFaq): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        $faqEntry = $this->faqs->find($faq);

        if ($faqEntry === null) {
            return ApiResponse::error('FAQ not found.', 'NOT_FOUND', 404);
        }

        $faqEntry = $updateFaq->handle($admin, $faqEntry, $request->validated());

        return ApiResponse::success(
            'FAQ updated successfully.',
            new AdminFaqResource($faqEntry),
        );
    }

    public function destroy(Request $request, int $faq, DeleteFaqAction $deleteFaq): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        $faqEntry = $this->faqs->find($faq);

        if ($faqEntry === null) {
            return ApiResponse::error('FAQ not found.', 'NOT_FOUND', 404);
        }

        $deleteFaq->handle($admin, $faqEntry);

        return ApiResponse::success('FAQ deleted successfully.');
    }
}
