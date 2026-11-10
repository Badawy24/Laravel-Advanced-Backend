<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Payment;
use BackedEnum;
use Illuminate\Http\Request;

/**
 * @mixin Payment
 */
class PaymentResource extends BaseApiResource
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
            'idempotency_key' => $this->idempotency_key,
            'amount' => $this->formatPrice($this->amount),
            'currency' => $this->currency,
            'status' => $this->status instanceof BackedEnum ? $this->status->value : $this->status,
            'failure_reason' => $this->failure_reason,
            'provider_reference' => $this->provider_reference,
            'applied_at' => $this->formatIsoDateTime($this->applied_at),
            'order' => new OrderResource($this->whenLoaded('order')),
            'events' => PaymentEventResource::collection($this->whenLoaded('events')),
            'created_at' => $this->formatIsoDateTime($this->created_at),
            'updated_at' => $this->formatIsoDateTime($this->updated_at),
        ];
    }
}
