<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Auth\ChangePasswordRequest;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Resources\Api\AuthResponseResource;
use App\Http\Resources\Api\UserResource;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function __construct(
        private readonly AuthService $authService
    ) {}

    /**
     * Register a new customer user.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return $this->createdResponse(
            new AuthResponseResource($result['user'], $result['token']),
            __('api.registered_successfully')
        );
    }

    /**
     * Authenticate a user and issue a token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            (string) $request->input('username'),
            (string) $request->input('password')
        );

        return $this->successResponse(
            new AuthResponseResource($result['user'], $result['token']),
            __('api.logged_in_successfully')
        );
    }

    /**
     * Retrieve the authenticated user's profile.
     */
    public function profile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('customer');

        return $this->successResponse(
            new UserResource($user),
            __('api.profile_retrieved_successfully')
        );
    }

    /**
     * Revoke the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authService->logout($user);

        return $this->successResponse(
            null,
            __('api.logged_out_successfully')
        );
    }

    /**
     * Revoke all access tokens for the authenticated user.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authService->logoutAll($user);

        return $this->successResponse(
            null,
            __('api.logged_out_all_successfully')
        );
    }

    /**
     * Change the authenticated user's password and revoke all other tokens.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authService->changePassword(
            $user,
            (string) $request->input('current_password'),
            (string) $request->input('password')
        );

        return $this->successResponse(
            null,
            __('api.password_changed_successfully')
        );
    }
}
