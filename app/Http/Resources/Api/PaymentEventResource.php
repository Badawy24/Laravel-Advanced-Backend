<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\PaymentEvent;
use Illuminate\Http\Request;

/**
 * @mixin PaymentEvent
 */
class PaymentEventResource extends BaseApiResource
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
            'payment_id' => $this->payment_id,
            'event_id' => $this->event_id,
            'type' => $this->type,
            'payload' => $this->payload,
            'processed_at' => $this->formatIsoDateTime($this->processed_at),
            'created_at' => $this->formatIsoDateTime($this->created_at),
        ];
    }
}
