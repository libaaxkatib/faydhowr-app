<?php

namespace Tests\Unit\Home;

use App\Services\Home\HomeCacheInvalidator;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HomeCacheInvalidatorTest extends TestCase
{
    public function test_invalidate_forgets_only_the_recorded_home_keys(): void
    {
        $invalidator = new HomeCacheInvalidator;

        Cache::put('home:aggregate', ['cached'], 300);
        Cache::put('home:section:faq:1:20', ['cached'], 300);
        Cache::put('unrelated:key', 'untouched', 300);

        $invalidator->record('home:aggregate');
        $invalidator->record('home:section:faq:1:20');
        $invalidator->record('home:aggregate');

        $invalidator->invalidate();

        $this->assertNull(Cache::get('home:aggregate'));
        $this->assertNull(Cache::get('home:section:faq:1:20'));
        $this->assertSame('untouched', Cache::get('unrelated:key'));
        $this->assertNull(Cache::get(HomeCacheInvalidator::KEY_INDEX));
    }

    public function test_invalidate_is_a_no_op_when_nothing_was_recorded(): void
    {
        $invalidator = new HomeCacheInvalidator;

        Cache::put('unrelated:key', 'untouched', 300);

        $invalidator->invalidate();

        $this->assertSame('untouched', Cache::get('unrelated:key'));
    }
}
