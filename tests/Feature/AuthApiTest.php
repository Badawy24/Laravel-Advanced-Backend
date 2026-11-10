<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiKey = (string) config('api.key');
    }

    /**
     * Helper to perform authenticated JSON requests with API key.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    private function authPostJson(string $uri, array $data = [], string $token = '', array $headers = [])
    {
        app('auth')->forgetGuards();
        $headers['X-APP-ACCESS-KEY'] = $this->apiKey;

        if ($token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->postJson($uri, $data, $headers);
    }

    /**
     * Helper to perform authenticated GET requests with API key.
     *
     * @param  array<string, string>  $headers
     */
    private function authGetJson(string $uri, string $token = '', array $headers = [])
    {
        app('auth')->forgetGuards();
        $headers['X-APP-ACCESS-KEY'] = $this->apiKey;

        if ($token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->getJson($uri, $headers);
    }

    /**
     * Helper to perform authenticated PUT requests with API key.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    private function authPutJson(string $uri, array $data = [], string $token = '', array $headers = [])
    {
        app('auth')->forgetGuards();
        $headers['X-APP-ACCESS-KEY'] = $this->apiKey;

        if ($token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->putJson($uri, $data, $headers);
    }

    public function test_register_creates_user_and_customer_profile_and_returns_token(): void
    {
        $payload = [
            'username' => 'newcustomer',
            'name' => 'New Customer',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ];

        $response = $this->authPostJson('/api/v1/auth/register', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'username' => 'newcustomer',
                        'name' => 'New Customer',
                        'role' => 'customer',
                    ],
                    'token_type' => 'Bearer',
                ],
            ]);

        $this->assertNotEmpty($response->json('data.token'));

        $this->assertDatabaseHas('users', [
            'username' => 'newcustomer',
            'name' => 'New Customer',
            'role' => 'customer',
            'email' => null,
        ]);

        $user = User::where('username', 'newcustomer')->firstOrFail();
        $this->assertTrue(Hash::check('Password123!', $user->password));
        $this->assertNotEquals('Password123!', $user->password);

        $this->assertDatabaseHas('customers', [
            'user_id' => $user->id,
        ]);
    }

    public function test_register_normalizes_username_to_lowercase_and_trims(): void
    {
        $payload = [
            'username' => '  MixedCaseUser_99  ',
            'name' => 'Mixed Case User',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ];

        $response = $this->authPostJson('/api/v1/auth/register', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'username' => 'mixedcaseuser_99',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'username' => 'mixedcaseuser_99',
        ]);
    }

    public function test_register_fails_with_duplicate_username(): void
    {
        User::factory()->create([
            'username' => 'existinguser',
        ]);

        $payload = [
            'username' => 'existinguser',
            'name' => 'Another User',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ];

        $response = $this->authPostJson('/api/v1/auth/register', $payload);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'success' => false,
            ])
            ->assertJsonValidationErrors(['username']);
    }

    public function test_register_validation_failures(): void
    {
        $payload = [
            'username' => 'a',
            'name' => 'ab',
            'password' => 'weak',
            'password_confirmation' => 'mismatch',
        ];

        $response = $this->authPostJson('/api/v1/auth/register', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['username', 'name', 'password']);
    }

    public function test_login_success(): void
    {
        $user = User::factory()->create([
            'username' => 'johndoe',
            'password' => 'Password123!',
            'role' => UserRole::Customer,
        ]);
        Customer::create(['user_id' => $user->id]);

        $payload = [
            'username' => 'johndoe',
            'password' => 'Password123!',
        ];

        $response = $this->authPostJson('/api/v1/auth/login', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'username' => 'johndoe',
                        'role' => 'customer',
                    ],
                    'token_type' => 'Bearer',
                ],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_fails_with_wrong_password_and_unknown_username_returning_identical_response(): void
    {
        User::factory()->create([
            'username' => 'existingperson',
            'password' => 'Password123!',
        ]);

        // Wrong password for existing user
        $responseWrongPassword = $this->authPostJson('/api/v1/auth/login', [
            'username' => 'existingperson',
            'password' => 'IncorrectPassword123!',
        ]);

        // Non-existent user
        $responseUnknownUser = $this->authPostJson('/api/v1/auth/login', [
            'username' => 'unknownperson404',
            'password' => 'IncorrectPassword123!',
        ]);

        $responseWrongPassword->assertStatus(401);
        $responseUnknownUser->assertStatus(401);

        $this->assertSame(
            $responseWrongPassword->json('message'),
            $responseUnknownUser->json('message')
        );
        $this->assertSame(
            $responseWrongPassword->json('status'),
            $responseUnknownUser->json('status')
        );
        $this->assertSame(
            $responseWrongPassword->json('success'),
            $responseUnknownUser->json('success')
        );
    }

    public function test_login_rate_limiting_after_five_attempts(): void
    {
        $payload = [
            'username' => 'ratelimituser',
            'password' => 'WrongPassword123!',
        ];

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->authPostJson('/api/v1/auth/login', $payload);
            $response->assertStatus(401);
        }

        // 6th attempt should be throttled
        $rateLimitedResponse = $this->authPostJson('/api/v1/auth/login', $payload);

        $rateLimitedResponse->assertStatus(429)
            ->assertJson([
                'status' => 'error',
                'success' => false,
            ]);
    }

    public function test_profile_requires_authentication(): void
    {
        $response = $this->authGetJson('/api/v1/auth/profile');

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'success' => false,
            ]);
    }

    public function test_profile_returns_authenticated_user_with_token(): void
    {
        $user = User::factory()->create([
            'username' => 'profileuser',
            'role' => UserRole::Customer,
        ]);
        Customer::create([
            'user_id' => $user->id,
            'phone' => '+123456789',
            'address' => 'Sample Address',
        ]);

        $token = $user->createToken('api')->plainTextToken;

        $response = $this->authGetJson('/api/v1/auth/profile', $token);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'username' => 'profileuser',
                    'role' => 'customer',
                    'customer' => [
                        'phone' => '+123456789',
                        'address' => 'Sample Address',
                    ],
                ],
            ]);
    }

    public function test_logout_revokes_current_token_only(): void
    {
        $user = User::factory()->create();

        $token1 = $user->createToken('device1')->plainTextToken;
        $token2 = $user->createToken('device2')->plainTextToken;

        $this->assertEquals(2, $user->tokens()->count());

        $response = $this->authPostJson('/api/v1/auth/logout', [], $token1);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertEquals(1, $user->tokens()->count());

        // Token 1 is now rejected
        $profileWithToken1 = $this->authGetJson('/api/v1/auth/profile', $token1);
        $profileWithToken1->assertStatus(401);

        // Token 2 still works
        $profileWithToken2 = $this->authGetJson('/api/v1/auth/profile', $token2);
        $profileWithToken2->assertStatus(200);
    }

    public function test_logout_all_revokes_all_tokens(): void
    {
        $user = User::factory()->create();

        $token1 = $user->createToken('device1')->plainTextToken;
        $token2 = $user->createToken('device2')->plainTextToken;
        $token3 = $user->createToken('device3')->plainTextToken;

        $this->assertEquals(3, $user->tokens()->count());

        $response = $this->authPostJson('/api/v1/auth/logout-all', [], $token1);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertEquals(0, $user->tokens()->count());

        $this->authGetJson('/api/v1/auth/profile', $token1)->assertStatus(401);
        $this->authGetJson('/api/v1/auth/profile', $token2)->assertStatus(401);
        $this->authGetJson('/api/v1/auth/profile', $token3)->assertStatus(401);
    }

    public function test_change_password_wrong_current_password_rejected(): void
    {
        $user = User::factory()->create([
            'username' => 'passchangeuser',
            'password' => 'InitialPassword123!',
        ]);

        $token = $user->createToken('api')->plainTextToken;

        $response = $this->authPutJson('/api/v1/auth/password', [
            'current_password' => 'WrongPassword123!',
            'password' => 'NewValidPassword123!',
            'password_confirmation' => 'NewValidPassword123!',
        ], $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('InitialPassword123!', $user->fresh()->password));
    }

    public function test_change_password_success_and_revokes_other_tokens(): void
    {
        $user = User::factory()->create([
            'username' => 'passsuccessuser',
            'password' => 'InitialPassword123!',
        ]);

        $tokenCurrent = $user->createToken('current_session')->plainTextToken;
        $tokenOther = $user->createToken('other_session')->plainTextToken;

        $this->assertEquals(2, $user->tokens()->count());

        $response = $this->authPutJson('/api/v1/auth/password', [
            'current_password' => 'InitialPassword123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ], $tokenCurrent);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // Old password stops working, new password works
        $user->refresh();
        $this->assertFalse(Hash::check('InitialPassword123!', $user->password));
        $this->assertTrue(Hash::check('NewPassword123!', $user->password));

        // Other token revoked, current token kept
        $this->assertEquals(1, $user->tokens()->count());
        $this->authGetJson('/api/v1/auth/profile', $tokenCurrent)->assertStatus(200);
        $this->authGetJson('/api/v1/auth/profile', $tokenOther)->assertStatus(401);

        // Can login with new password
        $loginResponse = $this->authPostJson('/api/v1/auth/login', [
            'username' => 'passsuccessuser',
            'password' => 'NewPassword123!',
        ]);
        $loginResponse->assertStatus(200);
    }
}
