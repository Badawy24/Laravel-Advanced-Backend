<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Order;
use BackedEnum;
use Illuminate\Http\Request;

/**
 * @mixin Order
 */
class OrderResource extends BaseApiResource
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
            'number' => $this->number,
            'customer_id' => $this->customer_id,
            'branch_id' => $this->branch_id,
            'status' => $this->status instanceof BackedEnum ? $this->status->value : $this->status,
            'total_amount' => $this->formatPrice($this->total_amount),
            'currency' => $this->currency,
            'idempotency_key' => $this->idempotency_key,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->formatIsoDateTime($this->created_at),
            'updated_at' => $this->formatIsoDateTime($this->updated_at),
        ];
    }
}
