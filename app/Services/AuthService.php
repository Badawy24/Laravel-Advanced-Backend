<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    /**
     * Register a new customer user and create their customer profile.
     *
     * @param  array<string, mixed>  $data
     * @return array{user: User, token: string}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $user = User::create([
                'username' => $data['username'],
                'name' => $data['name'],
                'password' => $data['password'],
                'role' => UserRole::Customer,
            ]);

            $user->customer()->create([]);
            $user->load('customer');

            $token = $user->createToken('api')->plainTextToken;

            return [
                'user' => $user,
                'token' => $token,
            ];
        });
    }

    /**
     * Authenticate a user and issue a new API token.
     *
     * @return array{user: User, token: string}
     *
     * @throws InvalidCredentialsException
     */
    public function login(string $username, string $password): array
    {
        $user = User::with('customer')->where('username', $username)->first();

        if ($user === null) {
            // Perform dummy hash check to mitigate timing attacks / user enumeration
            Hash::check($password, '$2y$04$YyXCABLbU/zD7BkG9NZuG.uvbDWM3wFjRo9HKwYWRAe/2YUgRM6/.');

            throw new InvalidCredentialsException(__('api.invalid_credentials'));
        }

        if (! Hash::check($password, $user->password)) {
            throw new InvalidCredentialsException(__('api.invalid_credentials'));
        }

        $token = $user->createToken('api')->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    /**
     * Revoke the current access token.
     */
    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    /**
     * Revoke all access tokens for the user.
     */
    public function logoutAll(User $user): void
    {
        $user->tokens()->delete();
    }

    /**
     * Change user password and revoke all other tokens.
     *
     * @throws InvalidCredentialsException
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new InvalidCredentialsException(__('api.current_password_invalid'));
        }

        DB::transaction(function () use ($user, $newPassword): void {
            $user->update([
                'password' => $newPassword,
            ]);

            $currentTokenId = $user->currentAccessToken()?->id;

            if ($currentTokenId !== null) {
                $user->tokens()->where('id', '!=', $currentTokenId)->delete();
            } else {
                $user->tokens()->delete();
            }
        });
    }
}
