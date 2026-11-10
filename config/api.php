<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API Secret Key
    |--------------------------------------------------------------------------
    |
    | This key must be supplied in the request headers (X-APP-ACCESS-KEY) for any
    | request attempting to access API endpoints.
    |
    */

    'key' => env('APP_ACCESS_KEY'),

    /*
    |--------------------------------------------------------------------------
    | API Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Maximum number of requests allowed per minute across all API endpoints.
    |
    */

    'rate_limit' => (int) env('APP_API_RATE_LIMIT', 60),

];
