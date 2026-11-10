<?php

namespace App\Models;

use App\Enums\InventoryMovementReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryMovement extends Model
{
    use HasFactory;

    /**
     * The name of the "updated at" column.
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'branch_inventory_id',
        'user_id',
        'reason',
        'quantity_delta',
        'reserved_delta',
        'quantity_after',
        'reserved_after',
        'reference_type',
        'reference_id',
        'note',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => InventoryMovementReason::class,
            'quantity_delta' => 'integer',
            'reserved_delta' => 'integer',
            'quantity_after' => 'integer',
            'reserved_after' => 'integer',
            'reference_id' => 'integer',
        ];
    }

    /**
     * Get the branch inventory record for this movement.
     */
    public function branchInventory(): BelongsTo
    {
        return $this->belongsTo(BranchInventory::class);
    }

    /**
     * Get the user who initiated this movement, if any.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the referenced model associated with this movement.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}

