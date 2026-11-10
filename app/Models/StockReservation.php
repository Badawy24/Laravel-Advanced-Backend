<?php

namespace App\Models;

use App\Enums\StockReservationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'order_item_id',
        'branch_inventory_id',
        'quantity',
        'status',
        'expires_at',
        'resolved_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'status' => StockReservationStatus::class,
            'expires_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Get the order item that owns this reservation.
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * Get the branch inventory record tied to this reservation.
     */
    public function branchInventory(): BelongsTo
    {
        return $this->belongsTo(BranchInventory::class);
    }
}

