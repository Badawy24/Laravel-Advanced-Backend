<?php

declare(strict_types=1);

namespace App\Traits;

use App\Support\Pagination;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;

trait ApiResponses
{
    /**
     * Generate a UTC timestamp in ISO 8601 format.
     */
    protected function apiTimestamp(): string
    {
        return now('UTC')->toISOString();
    }

    /**
     * Standard success response.
     */
    protected function successResponse(
        mixed $data = null,
        string $message = 'Success',
        int $code = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => $this->apiTimestamp(),
        ], $code);
    }

    /**
     * Standard error response.
     */
    protected function errorResponse(
        string $message = 'Error',
        int $code = 400,
        mixed $errors = null,
        mixed $data = null
    ): JsonResponse {
        $response = [
            'status' => 'error',
            'success' => false,
            'message' => $message,
            'timestamp' => $this->apiTimestamp(),
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    /**
     * Bad request response.
     */
    protected function badRequestResponse(
        string $message = 'Bad request',
        mixed $errors = null,
        mixed $data = null
    ): JsonResponse {
        return $this->errorResponse($message, 400, $errors, $data);
    }

    /**
     * Validation error response.
     */
    protected function validationErrorResponse(
        mixed $errors,
        string $message = 'Validation failed'
    ): JsonResponse {
        return $this->errorResponse($message, 422, $errors);
    }

    /**
     * Not found response.
     */
    protected function notFoundResponse(
        string $message = 'Resource not found'
    ): JsonResponse {
        return $this->errorResponse($message, 404);
    }

    /**
     * Unauthorized response.
     */
    protected function unauthorizedResponse(
        string $message = 'Unauthorized'
    ): JsonResponse {
        return $this->errorResponse($message, 401);
    }

    /**
     * Forbidden response.
     */
    protected function forbiddenResponse(
        string $message = 'Forbidden',
        mixed $data = null
    ): JsonResponse {
        return $this->errorResponse($message, 403, null, $data);
    }

    /**
     * Internal server error response.
     */
    protected function serverErrorResponse(
        string $message = 'Internal server error'
    ): JsonResponse {
        return $this->errorResponse($message, 500);
    }

    /**
     * Resource created response.
     */
    protected function createdResponse(
        mixed $data = null,
        string $message = 'Resource created successfully'
    ): JsonResponse {
        return $this->successResponse($data, $message, 201);
    }

    /**
     * Resource updated response.
     */
    protected function updatedResponse(
        mixed $data = null,
        string $message = 'Resource updated successfully'
    ): JsonResponse {
        return $this->successResponse($data, $message, 200);
    }

    /**
     * Resource deleted response.
     */
    protected function deletedResponse(
        string $message = 'Resource deleted successfully'
    ): JsonResponse {
        return $this->successResponse(null, $message, 200);
    }

    /**
     * Paginated response.
     */
    protected function paginatedResponse(
        LengthAwarePaginator $data,
        string $message = 'Data retrieved successfully'
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data->items(),
            'pagination' => [
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
                'from' => $data->firstItem(),
                'to' => $data->lastItem(),
            ],
            'timestamp' => $this->apiTimestamp(),
        ]);
    }

    /**
     * Paginate a query and return a standard API response.
     *
     * @param  class-string<JsonResource>|null  $resourceClass
     */
    protected function paginatedQueryResponse(
        Builder $query,
        Request $request,
        string $message = 'Data retrieved successfully',
        ?string $resourceClass = null,
        int $defaultPerPage = Pagination::DEFAULT_PER_PAGE
    ): JsonResponse {
        $paginator = Pagination::paginate($query, $request, $defaultPerPage);

        if ($resourceClass !== null) {
            $paginator->through(fn ($item) => (new $resourceClass($item))->resolve($request));
        }

        return $this->paginatedResponse($paginator, $message);
    }

    /**
     * Return a paginated API response where items, pagination, and count are wrapped into the 'data' payload.
     *
     * @param  LengthAwarePaginator|array<mixed>  $paginatorOrItems
     * @param  array<mixed>|null  $items
     * @param  array<string, mixed>  $extra
     */
    protected function paginatedDataResponse(
        LengthAwarePaginator|array $paginatorOrItems,
        ?array $items = null,
        array $extra = [],
        string $message = 'Data retrieved successfully',
        int $defaultPerPage = Pagination::DEFAULT_PER_PAGE
    ): JsonResponse {
        if ($paginatorOrItems instanceof LengthAwarePaginator) {
            $total = $paginatorOrItems->total();
            $resolvedItems = $items ?? $paginatorOrItems->items();
            $pagination = [
                'current_page' => $paginatorOrItems->currentPage(),
                'last_page' => $paginatorOrItems->lastPage(),
                'per_page' => $paginatorOrItems->perPage(),
                'total' => $total,
                'from' => $paginatorOrItems->firstItem(),
                'to' => $paginatorOrItems->lastItem(),
            ];
        } else {
            $resolvedItems = $items ?? array_values($paginatorOrItems);
            $total = count($resolvedItems);
            $pagination = [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $defaultPerPage,
                'total' => $total,
                'from' => $total > 0 ? 1 : null,
                'to' => $total > 0 ? $total : null,
            ];
        }

        $payload = array_merge([
            'count' => $total,
            'data' => $resolvedItems,
            'pagination' => $pagination,
        ], $extra);

        return $this->successResponse($payload, $message);
    }

    /**
     * Single API resource response.
     */
    protected function resourceResponse(
        JsonResource $resource,
        string $message = 'Data retrieved successfully'
    ): JsonResponse {
        return $this->successResponse(
            $resource->resolve(request()),
            $message
        );
    }

    /**
     * API resource collection response.
     */
    protected function resourceCollectionResponse(
        ResourceCollection $collection,
        string $message = 'Data retrieved successfully'
    ): JsonResponse {
        return $this->successResponse(
            $collection->resolve(request()),
            $message
        );
    }

    /**
     * Empty response with HTTP 204.
     */
    protected function noContentResponse(): Response
    {
        return response()->noContent();
    }

    /**
     * Conflict response.
     */
    protected function conflictResponse(
        string $message = 'Conflict occurred'
    ): JsonResponse {
        return $this->errorResponse($message, 409);
    }

    /**
     * Too many requests response.
     */
    protected function tooManyRequestsResponse(
        string $message = 'Too many requests'
    ): JsonResponse {
        return $this->errorResponse($message, 429);
    }

    /**
     * Service unavailable response.
     */
    protected function serviceUnavailableResponse(
        string $message = 'Service temporarily unavailable'
    ): JsonResponse {
        return $this->errorResponse($message, 503);
    }
}
