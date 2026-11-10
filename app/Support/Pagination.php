<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class Pagination
{
    public const int DEFAULT_PER_PAGE = 50;

    public const int MIN_PER_PAGE = 1;

    public const int MAX_PER_PAGE = 100;

    public const int DEFAULT_PAGE = 1;

    /**
     * Normalize pagination parameters.
     */
    public static function sanitizeParams(
        Request $request,
        int $defaultPerPage = self::DEFAULT_PER_PAGE
    ): array {
        $pageInput = $request->input(
            'page',
            self::DEFAULT_PAGE
        );

        $perPageInput = $request->input(
            'per_page',
            $defaultPerPage
        );

        $page = self::normalizeInteger(
            $pageInput,
            self::DEFAULT_PAGE
        );

        $perPage = self::normalizeInteger(
            $perPageInput,
            $defaultPerPage
        );

        if (
            $perPage < self::MIN_PER_PAGE ||
            $perPage > self::MAX_PER_PAGE
        ) {
            $perPage = $defaultPerPage;
        }

        return [
            'page' => max(self::DEFAULT_PAGE, $page),
            'per_page' => $perPage,
        ];
    }

    /**
     * Convert a scalar integer input safely.
     */
    private static function normalizeInteger(
        mixed $value,
        int $default
    ): int {
        if (! is_int($value) && ! is_string($value)) {
            return $default;
        }

        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT
        );

        return $validated === false
            ? $default
            : $validated;
    }

    /**
     * Paginate an Eloquent query.
     */
    public static function paginate(
        Builder $query,
        Request $request,
        int $defaultPerPage = self::DEFAULT_PER_PAGE
    ): LengthAwarePaginator {
        $params = self::sanitizeParams($request, $defaultPerPage);

        return $query->paginate(
            $params['per_page'],
            ['*'],
            'page',
            $params['page']
        );
    }
}
