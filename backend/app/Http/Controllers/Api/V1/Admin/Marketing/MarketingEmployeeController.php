<?php

namespace App\Http\Controllers\Api\V1\Admin\Marketing;

use App\Actions\Marketing\CreateMarketingTeamMemberAction;
use App\Actions\Marketing\ListMarketingEmployeesAction;
use App\Actions\Marketing\UpdateMarketingTeamMemberAction;
use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Marketing\StoreMarketingTeamMemberRequest;
use App\Http\Requests\Api\V1\Admin\Marketing\UpdateMarketingTeamMemberRequest;
use App\Http\Resources\Api\V1\Admin\Marketing\MarketingEmployeeResource;
use App\Models\Admin;
use App\Support\ApiResponse;
use App\Support\Marketing\MarketingScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketingEmployeeController extends Controller
{
    public function index(Request $request, ListMarketingEmployeesAction $action): JsonResponse
    {
        return ApiResponse::success('Marketing employees retrieved successfully.', MarketingEmployeeResource::collection($action->handle($request->user(), $request->boolean('include_inactive'))));
    }

    /**
     * Add Team Member: creates a Marketing Employee account and places it in a team. The role is fixed on the server (never
     * taken from the request); only the Super Admin and a Marketing Manager of that team may do it.
     */
    public function store(StoreMarketingTeamMemberRequest $request, CreateMarketingTeamMemberAction $action): JsonResponse
    {
        MarketingScope::requireUnrestricted($request->user(), 'Only the Marketing Manager can add team members.');
        MarketingScope::guardTeamManagement($request->user(), (int) $request->validated('team_id'));

        $member = $action->handle($request->user(), $request->validated());

        return ApiResponse::success('Team member added successfully.', new MarketingEmployeeResource($member->load('marketingTeams')), 201);
    }

    public function update(UpdateMarketingTeamMemberRequest $request, Admin $admin, UpdateMarketingTeamMemberAction $action): JsonResponse
    {
        MarketingScope::requireUnrestricted($request->user(), 'Only the Marketing Manager can change team members.');

        // Only Marketing Employee accounts, and only in a team the caller manages; anything else answers as if it did not exist.
        $managesATeamOfTheirs = $request->user()->role === AdminRole::SuperAdmin
            || $admin->marketingTeams()->pluck('marketing_teams.id')->contains(fn ($id) => MarketingScope::canManageTeam($request->user(), (int) $id));

        if ($admin->role !== AdminRole::MarketingEmployee || ! $managesATeamOfTheirs) {
            throw (new ModelNotFoundException)->setModel(Admin::class, [$admin->getKey()]);
        }

        $member = $action->handle($request->user(), $admin, $request->validated());

        return ApiResponse::success('Team member updated successfully.', new MarketingEmployeeResource($member->load('marketingTeams')));
    }
}
