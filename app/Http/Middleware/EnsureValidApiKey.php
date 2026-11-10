<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Traits\ApiResponses;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureValidApiKey
{
    use ApiResponses;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api', 'api/*')) {
            return $next($request);
        }

        if ($request->isMethod('OPTIONS') || $request->hasValidSignature()) {
            return $next($request);
        }

        $providedKey = $request->header('X-APP-ACCESS-KEY');

        if ($providedKey === null || trim((string) $providedKey) === '') {
            $message = __('api.api_key_missing');

            return $this->unauthorizedResponse(
                $message !== 'api.api_key_missing' ? $message : 'API key is missing in request headers.'
            );
        }

        $expectedKey = (string) config('api.key');

        if ($expectedKey === '') {
            $message = __('api.api_key_not_configured');

            return $this->serverErrorResponse(
                $message !== 'api.api_key_not_configured' ? $message : 'API key is not configured on the server.'
            );
        }

        if (! hash_equals($expectedKey, trim((string) $providedKey))) {
            $message = __('api.api_key_invalid');

            return $this->unauthorizedResponse(
                $message !== 'api.api_key_invalid' ? $message : 'The provided API key is invalid.'
            );
        }

        return $next($request);
    }
}
