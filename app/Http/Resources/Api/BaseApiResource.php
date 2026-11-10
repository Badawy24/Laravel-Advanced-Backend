<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Traits\FormatsApiData;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class BaseApiResource extends JsonResource
{
    use FormatsApiData;
}
