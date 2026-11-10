<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\User;
use BackedEnum;
use Illuminate\Http\Request;

/**
 * @mixin User
 */
class UserResource extends BaseApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role instanceof BackedEnum ? $this->role->value : (string) $this->role,
            'customer' => $this->relationLoaded('customer') && $this->customer !== null ? [
                'id' => $this->customer->id,
                'phone' => $this->customer->phone,
                'address' => $this->customer->address,
            ] : null,
            'created_at' => $this->formatIsoDateTime($this->created_at),
            'updated_at' => $this->formatIsoDateTime($this->updated_at),
        ];
    }
}
