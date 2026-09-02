<?php

namespace App\Http\Controllers\Api\V1\Admin\Marketing;

use App\Actions\Marketing\AddMarketingTeamMemberAction;
use App\Actions\Marketing\CreateMarketingTeamAction;
use App\Actions\Marketing\DeleteMarketingTeamAction;
use App\Actions\Marketing\ListMarketingTeamsAction;
use App\Actions\Marketing\RemoveMarketingTeamMemberAction;
use App\Actions\Marketing\UpdateMarketingTeamAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Marketing\AddMarketingTeamMemberRequest;
use App\Http\Requests\Api\V1\Admin\Marketing\StoreMarketingTeamRequest;
use App\Http\Requests\Api\V1\Admin\Marketing\UpdateMarketingTeamRequest;
use App\Http\Resources\Api\V1\Admin\Marketing\MarketingTeamResource;
use App\Models\MarketingTeam;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class MarketingTeamController extends Controller
{
    public function index(ListMarketingTeamsAction $action): JsonResponse
    {
        return ApiResponse::success('Marketing teams retrieved successfully.', MarketingTeamResource::collection($action->handle()));
    }

    public function store(StoreMarketingTeamRequest $request, CreateMarketingTeamAction $action): JsonResponse
    {
        $team = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('Marketing team created successfully.', new MarketingTeamResource($team), 201);
    }

    public function update(UpdateMarketingTeamRequest $request, MarketingTeam $team, UpdateMarketingTeamAction $action): JsonResponse
    {
        $team = $action->handle($team, $request->validated(), $request->user());

        return ApiResponse::success('Marketing team updated successfully.', new MarketingTeamResource($team));
    }

    public function destroy(MarketingTeam $team, DeleteMarketingTeamAction $action): JsonResponse
    {
        $action->handle($team, request()->user());

        return ApiResponse::success('Marketing team deleted successfully.');
    }

    public function addMember(AddMarketingTeamMemberRequest $request, MarketingTeam $team, AddMarketingTeamMemberAction $action): JsonResponse
    {
        $team = $action->handle($team, (int) $request->validated('admin_id'), $request->user());

        return ApiResponse::success('Member added successfully.', new MarketingTeamResource($team));
    }

    public function removeMember(MarketingTeam $team, int $admin, RemoveMarketingTeamMemberAction $action): JsonResponse
    {
        $team = $action->handle($team, $admin, request()->user());

        return ApiResponse::success('Member removed successfully.', new MarketingTeamResource($team));
    }
}
