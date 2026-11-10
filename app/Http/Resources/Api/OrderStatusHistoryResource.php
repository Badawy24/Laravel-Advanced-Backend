<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\OrderStatusHistory;
use BackedEnum;
use Illuminate\Http\Request;

/**
 * @mixin OrderStatusHistory
 */
class OrderStatusHistoryResource extends BaseApiResource
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
            'from_status' => $this->from_status instanceof BackedEnum ? $this->from_status->value : $this->from_status,
            'to_status' => $this->to_status instanceof BackedEnum ? $this->to_status->value : $this->to_status,
            'changed_by' => $this->changed_by,
            'note' => $this->note,
            'created_at' => $this->formatIsoDateTime($this->created_at),
        ];
    }
}
