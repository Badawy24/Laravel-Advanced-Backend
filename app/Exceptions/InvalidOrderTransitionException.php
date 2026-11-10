<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class InvalidOrderTransitionException extends Exception
{
    public function __construct(string $message = '')
    {
        parent::__construct($message ?: __('api.invalid_order_transition'));
    }
}
