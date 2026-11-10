<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Branch;
use Illuminate\Http\Request;

/**
 * @mixin Branch
 */
class BranchResource extends BaseApiResource
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
            'name' => $this->name,
            'code' => $this->code,
            'city' => $this->city,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
