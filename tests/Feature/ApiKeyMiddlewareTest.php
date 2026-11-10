<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiKeyMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/test-key', function () {
            return response()->json(['success' => true]);
        });
    }

    public function test_api_request_fails_without_access_key(): void
    {
        $response = $this->getJson('/api/test-key');

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'success' => false,
            ]);
    }

    public function test_api_request_fails_with_invalid_access_key(): void
    {
        $response = $this->getJson('/api/test-key', [
            'X-APP-ACCESS-KEY' => 'invalid_key',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'success' => false,
            ]);
    }

    public function test_api_request_succeeds_with_valid_access_key(): void
    {
        $validKey = (string) config('api.key');

        $response = $this->getJson('/api/test-key', [
            'X-APP-ACCESS-KEY' => $validKey,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }
}
