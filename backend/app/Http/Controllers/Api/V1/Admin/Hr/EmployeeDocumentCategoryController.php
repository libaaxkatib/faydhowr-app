<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\CreateEmployeeDocumentCategoryAction;
use App\Actions\Hr\ListEmployeeDocumentCategoriesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\StoreEmployeeDocumentCategoryRequest;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeDocumentCategoryResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployeeDocumentCategoryController extends Controller
{
    public function index(ListEmployeeDocumentCategoriesAction $action): JsonResponse
    {
        return ApiResponse::success('Document categories retrieved successfully.', EmployeeDocumentCategoryResource::collection($action->handle()));
    }

    public function store(StoreEmployeeDocumentCategoryRequest $request, CreateEmployeeDocumentCategoryAction $action): JsonResponse
    {
        $category = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('Document category created successfully.', new EmployeeDocumentCategoryResource($category), 201);
    }
}
