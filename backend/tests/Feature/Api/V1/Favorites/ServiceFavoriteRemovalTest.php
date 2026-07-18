<?php

namespace Tests\Feature\Api\V1\Favorites;

use App\Models\Favorite;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceFavoriteRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivating_a_service_removes_its_favorites_and_resets_count(): void
    {
        $favorite = Favorite::factory()->create();
        $otherFavorite = Favorite::factory()->create();

        $service = Service::query()->findOrFail($favorite->service_id);
        Service::query()->whereKey($service->id)->update(['favorites_count' => 1]);

        $service->update(['is_active' => false]);

        $this->assertDatabaseMissing('favorites', ['id' => $favorite->id]);
        $this->assertDatabaseHas('favorites', ['id' => $otherFavorite->id]);
        $this->assertSame(0, $service->refresh()->favorites_count);
    }

    public function test_soft_deleting_a_service_removes_its_favorites(): void
    {
        $favorite = Favorite::factory()->create();
        $otherFavorite = Favorite::factory()->create();

        $service = Service::query()->findOrFail($favorite->service_id);
        Service::query()->whereKey($service->id)->update(['favorites_count' => 1]);

        $service->delete();

        $this->assertDatabaseMissing('favorites', ['id' => $favorite->id]);
        $this->assertDatabaseHas('favorites', ['id' => $otherFavorite->id]);
        $this->assertSame(0, Service::withTrashed()->findOrFail($service->id)->favorites_count);
    }

    public function test_unrelated_service_updates_leave_favorites_untouched(): void
    {
        $favorite = Favorite::factory()->create();

        $service = Service::query()->findOrFail($favorite->service_id);
        Service::query()->whereKey($service->id)->update(['favorites_count' => 1]);

        $service->update(['sort_order' => 5]);

        $this->assertDatabaseHas('favorites', ['id' => $favorite->id]);
        $this->assertSame(1, $service->refresh()->favorites_count);
    }
}
