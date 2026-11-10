<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class InactiveBranchException extends Exception
{
    public function __construct(string $message = '')
    {
        parent::__construct($message ?: __('api.branch_inactive'));
    }
}
