<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class BranchInventoryNotFoundException extends Exception
{
    public function __construct(string $message = '')
    {
        parent::__construct($message ?: __('api.branch_inventory_not_found'));
    }
}
