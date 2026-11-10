<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Product;
use Illuminate\Http\Request;

/**
 * @mixin Product
 */
class ProductResource extends BaseApiResource
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
            'category_id' => $this->category_id,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->formatPrice($this->price),
            'is_active' => (bool) $this->is_active,
            'category' => $this->relationLoaded('category') && $this->category !== null
                ? (new CategoryResource($this->category))->resolve($request)
                : null,
            'created_at' => $this->formatIsoDateTime($this->created_at),
            'updated_at' => $this->formatIsoDateTime($this->updated_at),
        ];
    }
}
