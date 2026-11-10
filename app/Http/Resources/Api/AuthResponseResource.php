<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\User;
use Illuminate\Http\Request;

class AuthResponseResource extends BaseApiResource
{
    public function __construct(
        public User $user,
        public string $token,
        public string $tokenType = 'Bearer'
    ) {
        parent::__construct($user);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user' => (new UserResource($this->user))->resolve($request),
            'token' => $this->token,
            'token_type' => $this->tokenType,
        ];
    }
}
