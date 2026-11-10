<?php

declare(strict_types=1);

use App\Exceptions\InvalidCredentialsException;
use App\Http\Middleware\EnsureValidApiKey;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append([
            EnsureValidApiKey::class,
        ]);

        $middleware->alias([
            'api.key' => EnsureValidApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e): bool => $request->is('api', 'api/*') || $request->expectsJson()
        );

        $exceptions->render(function (InvalidCredentialsException $e, Request $request): JsonResponse {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : __('api.invalid_credentials'),
                'timestamp' => now('UTC')->toISOString(),
            ], 401);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request): ?JsonResponse {
            if ($request->is('api', 'api/*') || $request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'success' => false,
                    'message' => __('api.unauthorized'),
                    'timestamp' => now('UTC')->toISOString(),
                ], 401);
            }

            return null;
        });

        $exceptions->render(function (ValidationException $e, Request $request): ?JsonResponse {
            if ($request->is('api', 'api/*') || $request->expectsJson()) {
                $errors = $e->errors();
                $firstMessage = collect($errors)->flatten()->first() ?: __('api.validation_failed');

                return response()->json([
                    'status' => 'error',
                    'success' => false,
                    'message' => $firstMessage,
                    'errors' => $errors,
                    'timestamp' => now('UTC')->toISOString(),
                ], 422);
            }

            return null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request): ?JsonResponse {
            if ($request->is('api', 'api/*') || $request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'success' => false,
                    'message' => __('api.too_many_requests'),
                    'timestamp' => now('UTC')->toISOString(),
                ], 429);
            }

            return null;
        });
    })->create();
