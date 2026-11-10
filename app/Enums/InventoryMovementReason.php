<?php

namespace App\Enums;

enum InventoryMovementReason: string
{
    case Restock = 'restock';
    case Reserve = 'reserve';
    case Release = 'release';
    case Expire = 'expire';
    case Sale = 'sale';
    case Adjustment = 'adjustment';
}

