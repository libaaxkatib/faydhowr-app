<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class UnauthenticatedApiResponseTest extends TestCase
{
    public function test_protected_api_route_returns_401_without_accept_json_header(): void
    {
        $this->get('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJson(['error_code' => 'UNAUTHENTICATED']);
    }

    public function test_protected_api_route_returns_401_with_accept_json_header(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJson(['error_code' => 'UNAUTHENTICATED']);
    }
}
