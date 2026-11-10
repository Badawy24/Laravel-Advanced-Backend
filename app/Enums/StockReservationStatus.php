<?php

namespace App\Enums;

enum StockReservationStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Expired = 'expired';
    case Consumed = 'consumed';
}

