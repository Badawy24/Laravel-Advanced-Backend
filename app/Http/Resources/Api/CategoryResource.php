<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Category;
use Illuminate\Http\Request;

/**
 * @mixin Category
 */
class CategoryResource extends BaseApiResource
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
            'slug' => $this->slug,
        ];
    }
}
