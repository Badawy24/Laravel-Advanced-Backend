<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Stock Reservation Time-To-Live
    |--------------------------------------------------------------------------
    |
    | The duration in minutes that a stock reservation remains active before expiring.
    |
    */
    'reservation_ttl_minutes' => (int) env('RESERVATION_TTL_MINUTES', 15),
];
