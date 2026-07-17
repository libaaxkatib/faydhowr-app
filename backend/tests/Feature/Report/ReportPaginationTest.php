<?php

namespace Tests\Feature\Report;

use App\Data\Reports\NormalizedReportFilters;
use App\Data\Reports\ReportCursorPagination;
use App\Models\Admin;
use App\Models\Supplier;
use App\Repositories\Reports\SupplierReportRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ReportPaginationTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Admin::factory()->superAdmin()->create()
            ->createToken('admin-panel')->plainTextToken;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function generateSupplierReport(array $body = []): TestResponse
    {
        return $this
            ->withToken($this->token)
            ->postJson('/api/v1/admin/reports/suppliers', $body);
    }

    public function test_pagination_defaults_to_limit_50(): void
    {
        Supplier::factory()->count(3)->create();

        $this->generateSupplierReport()
            ->assertCreated()
            ->assertJsonPath('data.payload.pagination.per_page', ReportCursorPagination::DEFAULT_LIMIT)
            ->assertJsonPath('data.payload.pagination.count', 3)
            ->assertJsonPath('data.payload.pagination.has_more', false)
            ->assertJsonPath('data.payload.pagination.next_cursor', null)
            ->assertJsonPath('data.payload.pagination.previous_cursor', null);
    }

    public function test_pagination_metadata_has_exact_public_structure(): void
    {
        Supplier::factory()->create();

        $pagination = $this->generateSupplierReport(['limit' => 10])
            ->assertCreated()
            ->json('data.payload.pagination');

        $this->assertSame(
            ['has_more', 'next_cursor', 'previous_cursor', 'per_page', 'count'],
            array_keys($pagination),
        );
    }

    public function test_limit_below_one_is_rejected(): void
    {
        foreach ([0, -5] as $limit) {
            $this->generateSupplierReport(['limit' => $limit])
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'VALIDATION_ERROR');
        }
    }

    public function test_limit_above_maximum_is_rejected(): void
    {
        $this->generateSupplierReport(['limit' => 501])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_maximum_limit_is_accepted(): void
    {
        Supplier::factory()->create();

        $this->generateSupplierReport(['limit' => ReportCursorPagination::MAX_LIMIT])
            ->assertCreated()
            ->assertJsonPath('data.payload.pagination.per_page', ReportCursorPagination::MAX_LIMIT);
    }

    public function test_next_cursor_traverses_forward(): void
    {
        Supplier::factory()->count(5)->create();

        $firstPage = $this->generateSupplierReport(['limit' => 2])->assertCreated();

        $this->assertTrue($firstPage->json('data.payload.pagination.has_more'));
        $this->assertNotNull($firstPage->json('data.payload.pagination.next_cursor'));
        $this->assertCount(2, $firstPage->json('data.payload.rows'));

        $secondPage = $this->generateSupplierReport([
            'limit' => 2,
            'cursor' => $firstPage->json('data.payload.pagination.next_cursor'),
        ])->assertCreated();

        $this->assertCount(2, $secondPage->json('data.payload.rows'));
        $this->assertNotSame(
            array_column($firstPage->json('data.payload.rows'), 'id'),
            array_column($secondPage->json('data.payload.rows'), 'id'),
        );
    }

    public function test_previous_cursor_traverses_backward(): void
    {
        Supplier::factory()->count(5)->create();

        $firstPage = $this->generateSupplierReport(['limit' => 2])->assertCreated();
        $firstPageIds = array_column($firstPage->json('data.payload.rows'), 'id');

        $secondPage = $this->generateSupplierReport([
            'limit' => 2,
            'cursor' => $firstPage->json('data.payload.pagination.next_cursor'),
        ])->assertCreated();

        $previousCursor = $secondPage->json('data.payload.pagination.previous_cursor');
        $this->assertNotNull($previousCursor);

        $backToFirst = $this->generateSupplierReport([
            'limit' => 2,
            'cursor' => $previousCursor,
        ])->assertCreated();

        $this->assertSame($firstPageIds, array_column($backToFirst->json('data.payload.rows'), 'id'));
    }

    public function test_rows_are_deterministically_ordered(): void
    {
        $suppliers = Supplier::factory()->count(4)->create();
        $expectedIds = $suppliers->sortByDesc('id')->pluck('id')->values()->all();

        $rows = $this->generateSupplierReport(['limit' => 10])
            ->assertCreated()
            ->json('data.payload.rows');

        $this->assertSame($expectedIds, array_column($rows, 'id'));
    }

    public function test_filters_combine_with_cursor_traversal(): void
    {
        Supplier::factory()->count(3)->create();
        Supplier::factory()->inactive()->count(2)->create();

        $activeIds = Supplier::query()->where('status', 'active')->pluck('id')->sortDesc()->values()->all();

        $collected = [];
        $cursor = null;
        $pages = 0;

        do {
            $this->assertLessThan(10, $pages, 'Cursor traversal did not terminate.');

            $response = $this->generateSupplierReport(array_filter([
                'limit' => 2,
                'cursor' => $cursor,
                'filters' => ['status' => 'active'],
            ], fn (mixed $value): bool => $value !== null))->assertCreated();

            $collected = array_merge($collected, array_column($response->json('data.payload.rows'), 'id'));
            $cursor = $response->json('data.payload.pagination.next_cursor');
            $pages++;
        } while ($response->json('data.payload.pagination.has_more'));

        $this->assertSame($activeIds, $collected);
    }

    public function test_large_dataset_traversal_visits_every_row_exactly_once(): void
    {
        Supplier::factory()->count(120)->create();

        $collected = [];
        $cursor = null;
        $pages = 0;

        do {
            $this->assertLessThan(10, $pages, 'Cursor traversal did not terminate.');

            $response = $this->generateSupplierReport(array_filter([
                'limit' => 50,
                'cursor' => $cursor,
            ], fn (mixed $value): bool => $value !== null))->assertCreated();

            $rows = $response->json('data.payload.rows');
            $collected = array_merge($collected, array_column($rows, 'id'));
            $cursor = $response->json('data.payload.pagination.next_cursor');
            $pages++;
        } while ($response->json('data.payload.pagination.has_more'));

        $this->assertSame(3, $pages);
        $this->assertCount(120, $collected);
        $this->assertSame(array_unique($collected), $collected);
    }

    public function test_repository_rows_return_cursor_paginator_with_limit(): void
    {
        Supplier::factory()->count(3)->create();

        $paginator = $this->app->make(SupplierReportRepository::class)
            ->rows(new NormalizedReportFilters, new ReportCursorPagination(limit: 2));

        $this->assertCount(2, $paginator->items());
        $this->assertTrue($paginator->hasMorePages());
        $this->assertSame(2, $paginator->perPage());
        $this->assertNotNull($paginator->nextCursor());
    }
}
