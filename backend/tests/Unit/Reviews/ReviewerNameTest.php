<?php

namespace Tests\Unit\Reviews;

use App\Support\Review\ReviewerName;
use PHPUnit\Framework\TestCase;

class ReviewerNameTest extends TestCase
{
    public function test_two_part_names_render_first_name_plus_initial(): void
    {
        $this->assertSame('Hodan A.', ReviewerName::format('Hodan Abdi'));
    }

    public function test_multi_part_names_use_the_last_name_initial(): void
    {
        $this->assertSame('Mohamed H.', ReviewerName::format('Mohamed Ali Hassan'));
    }

    public function test_single_names_render_without_an_initial(): void
    {
        $this->assertSame('Hodan', ReviewerName::format('Hodan'));
    }

    public function test_extra_whitespace_is_ignored(): void
    {
        $this->assertSame('Hodan A.', ReviewerName::format('  Hodan   Abdi  '));
    }

    public function test_empty_names_fall_back_to_verified_customer(): void
    {
        $this->assertSame(ReviewerName::ANONYMOUS, ReviewerName::format(''));
        $this->assertSame(ReviewerName::ANONYMOUS, ReviewerName::format('   '));
    }

    public function test_null_profile_falls_back_to_verified_customer(): void
    {
        $this->assertSame('Verified Customer', ReviewerName::for(null));
    }
}
