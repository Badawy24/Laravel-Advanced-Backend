<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\OrderItem;
use Illuminate\Http\Request;

/**
 * @mixin OrderItem
 */
class OrderItemResource extends BaseApiResource
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
            'order_id' => $this->order_id,
            'product_id' => $this->product_id,
            'quantity' => $this->quantity,
            'unit_price' => $this->formatPrice($this->unit_price),
            'line_total' => $this->formatPrice($this->line_total),
            'created_at' => $this->formatIsoDateTime($this->created_at),
            'updated_at' => $this->formatIsoDateTime($this->updated_at),
        ];
    }
}
