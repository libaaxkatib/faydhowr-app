<?php

namespace Tests\Unit\Favorites;

use App\Contracts\Favorite\Repositories\FavoriteRepositoryInterface;
use App\Exceptions\Favorite\FavoriteServiceNotFoundException;
use App\Models\CustomerProfile;
use App\Models\Favorite;
use App\Models\Service;
use App\Services\Favorite\FavoriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class FavoriteServiceTest extends TestCase
{
    use RefreshDatabase;

    private FavoriteRepositoryInterface&MockObject $repository;

    private FavoriteService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(FavoriteRepositoryInterface::class);
        $this->service = new FavoriteService($this->repository);
    }

    public function test_add_throws_when_service_is_not_accessible(): void
    {
        $this->repository->method('findAccessibleService')->willReturn(null);
        $this->repository->expects($this->never())->method('create');

        $this->expectException(FavoriteServiceNotFoundException::class);

        $this->service->add($this->profile(), 42);
    }

    public function test_add_is_idempotent_when_already_favorited(): void
    {
        $existing = new Favorite;

        $this->repository->method('findAccessibleService')->willReturn($this->catalogService(7));
        $this->repository->method('findFor')->willReturn($existing);
        $this->repository->expects($this->never())->method('create');
        $this->repository->expects($this->never())->method('recalculateFavoritesCount');

        $result = $this->service->add($this->profile(), 7);

        $this->assertFalse($result['created']);
        $this->assertSame($existing, $result['favorite']);
    }

    public function test_add_creates_favorite_and_recalculates_count(): void
    {
        $created = new Favorite;

        $this->repository->method('findAccessibleService')->willReturn($this->catalogService(7));
        $this->repository->method('findFor')->willReturn(null);
        $this->repository->expects($this->once())->method('create')->willReturn($created);
        $this->repository->expects($this->once())->method('recalculateFavoritesCount')->with(7);

        $result = $this->service->add($this->profile(), 7);

        $this->assertTrue($result['created']);
        $this->assertSame($created, $result['favorite']);
    }

    public function test_remove_throws_when_service_is_not_accessible(): void
    {
        $this->repository->method('findAccessibleService')->willReturn(null);
        $this->repository->expects($this->never())->method('deleteFor');

        $this->expectException(FavoriteServiceNotFoundException::class);

        $this->service->remove($this->profile(), 42);
    }

    public function test_remove_recalculates_count_only_when_a_favorite_was_deleted(): void
    {
        $this->repository->method('findAccessibleService')->willReturn($this->catalogService(7));
        $this->repository->method('deleteFor')->willReturn(true);
        $this->repository->expects($this->once())->method('recalculateFavoritesCount')->with(7);

        $this->service->remove($this->profile(), 7);
    }

    public function test_remove_is_silent_when_nothing_was_deleted(): void
    {
        $this->repository->method('findAccessibleService')->willReturn($this->catalogService(7));
        $this->repository->method('deleteFor')->willReturn(false);
        $this->repository->expects($this->never())->method('recalculateFavoritesCount');

        $this->service->remove($this->profile(), 7);
    }

    public function test_remove_all_for_service_recalculates_only_when_rows_were_removed(): void
    {
        $this->repository->method('deleteAllForService')->willReturn(3);
        $this->repository->expects($this->once())->method('recalculateFavoritesCount')->with(7);

        $this->service->removeAllForService($this->catalogService(7));

        $silentRepository = $this->createMock(FavoriteRepositoryInterface::class);
        $silentRepository->method('deleteAllForService')->willReturn(0);
        $silentRepository->expects($this->never())->method('recalculateFavoritesCount');

        (new FavoriteService($silentRepository))->removeAllForService($this->catalogService(7));
    }

    private function profile(): CustomerProfile
    {
        $profile = new CustomerProfile;
        $profile->id = 11;

        return $profile;
    }

    private function catalogService(int $id): Service
    {
        $service = new Service;
        $service->id = $id;

        return $service;
    }
}
