<?php

namespace App\Http\Controllers\Api\V1\Admin\Marketing;

use App\Actions\Marketing\AddMarketingTeamMemberAction;
use App\Actions\Marketing\CreateMarketingTeamAction;
use App\Actions\Marketing\DeleteMarketingTeamAction;
use App\Actions\Marketing\ListMarketingTeamsAction;
use App\Actions\Marketing\RemoveMarketingTeamMemberAction;
use App\Actions\Marketing\UpdateMarketingTeamAction;
use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Marketing\AddMarketingTeamMemberRequest;
use App\Http\Requests\Api\V1\Admin\Marketing\StoreMarketingTeamRequest;
use App\Http\Requests\Api\V1\Admin\Marketing\UpdateMarketingTeamRequest;
use App\Http\Resources\Api\V1\Admin\Marketing\MarketingTeamResource;
use App\Models\Admin;
use App\Models\MarketingTeam;
use App\Support\ApiResponse;
use App\Support\Marketing\MarketingScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketingTeamController extends Controller
{
    public function index(Request $request, ListMarketingTeamsAction $action): JsonResponse
    {
        return ApiResponse::success('Marketing teams retrieved successfully.', MarketingTeamResource::collection($action->handle($request->user())));
    }

    public function store(StoreMarketingTeamRequest $request, CreateMarketingTeamAction $action): JsonResponse
    {
        MarketingScope::requireUnrestricted(request()->user(), 'Only the Marketing Manager can create marketing teams.');
        $team = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('Marketing team created successfully.', new MarketingTeamResource($team), 201);
    }

    public function update(UpdateMarketingTeamRequest $request, MarketingTeam $team, UpdateMarketingTeamAction $action): JsonResponse
    {
        MarketingScope::requireUnrestricted(request()->user(), 'Only the Marketing Manager can change marketing teams.');
        $team = $action->handle($team, $request->validated(), $request->user());

        return ApiResponse::success('Marketing team updated successfully.', new MarketingTeamResource($team));
    }

    public function destroy(MarketingTeam $team, DeleteMarketingTeamAction $action): JsonResponse
    {
        MarketingScope::requireUnrestricted(request()->user(), 'Only the Marketing Manager can delete marketing teams.');
        $action->handle($team, request()->user());

        return ApiResponse::success('Marketing team deleted successfully.');
    }

    public function addMember(AddMarketingTeamMemberRequest $request, MarketingTeam $team, AddMarketingTeamMemberAction $action): JsonResponse
    {
        MarketingScope::requireUnrestricted(request()->user(), 'Only the Marketing Manager can change team membership.');
        MarketingScope::guardTeamManagement($request->user(), $team->id);

        $member = Admin::query()->find((int) $request->validated('admin_id'));

        if ($member === null || ! in_array($member->role, [AdminRole::MarketingEmployee, AdminRole::MarketingManager], true)) {
            return ApiResponse::error('Only a marketing employee or a marketing manager can join a marketing team.', 'INVALID_TEAM_MEMBER', 422);
        }

        $team = $action->handle($team, (int) $request->validated('admin_id'), $request->user());

        return ApiResponse::success('Member added successfully.', new MarketingTeamResource($team));
    }

    public function removeMember(MarketingTeam $team, int $admin, RemoveMarketingTeamMemberAction $action): JsonResponse
    {
        MarketingScope::requireUnrestricted(request()->user(), 'Only the Marketing Manager can change team membership.');
        MarketingScope::guardTeamManagement(request()->user(), $team->id);
        $team = $action->handle($team, $admin, request()->user());

        return ApiResponse::success('Member removed successfully.', new MarketingTeamResource($team));
    }
}
