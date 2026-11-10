<?php

declare(strict_types=1);

namespace App\Exceptions;

class MissingActiveReservationException extends InvalidOrderTransitionException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message ?: __('api.missing_active_reservations'));
    }
}
