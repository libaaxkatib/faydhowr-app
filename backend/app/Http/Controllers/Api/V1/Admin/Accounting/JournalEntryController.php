<?php

namespace App\Http\Controllers\Api\V1\Admin\Accounting;

use App\Contracts\Accounting\AccountingManagerInterface;
use App\Exceptions\Accounting\InsufficientJournalLinesException;
use App\Exceptions\Accounting\InvalidJournalStatusException;
use App\Exceptions\Accounting\JournalNotBalancedException;
use App\Exceptions\Accounting\PostingToGroupAccountException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Accounting\ListJournalEntriesRequest;
use App\Http\Resources\Api\V1\Admin\Accounting\JournalEntryResource;
use App\Models\JournalEntry;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class JournalEntryController extends Controller
{
    public function __construct(private AccountingManagerInterface $accounting) {}

    public function index(ListJournalEntriesRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', JournalEntry::class);

        return ApiResponse::success(
            'Journal entries retrieved successfully.',
            JournalEntryResource::collection(
                $this->accounting->journalEntries()->latest($request->limit()),
            ),
        );
    }

    public function show(JournalEntry $journalEntry): JsonResponse
    {
        Gate::authorize('view', $journalEntry);

        return ApiResponse::success(
            'Journal entry retrieved successfully.',
            new JournalEntryResource($journalEntry->load('lines')),
        );
    }

    public function post(JournalEntry $journalEntry): JsonResponse
    {
        Gate::authorize('post', $journalEntry);

        try {
            $posted = $this->accounting->journalEntries()->post($journalEntry);
        } catch (InvalidJournalStatusException $exception) {
            return ApiResponse::error($exception->getMessage(), 'INVALID_JOURNAL_STATUS', 409);
        } catch (InsufficientJournalLinesException $exception) {
            return ApiResponse::error($exception->getMessage(), 'INSUFFICIENT_JOURNAL_LINES', 422);
        } catch (PostingToGroupAccountException $exception) {
            return ApiResponse::error($exception->getMessage(), 'POSTING_TO_GROUP_ACCOUNT', 422);
        } catch (JournalNotBalancedException $exception) {
            return ApiResponse::error($exception->getMessage(), 'JOURNAL_NOT_BALANCED', 422);
        }

        return ApiResponse::success(
            'Journal entry posted successfully.',
            new JournalEntryResource($posted->load('lines')),
        );
    }
}
