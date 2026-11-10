<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Traits\ApiResponses;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SimulatePaymentRequest extends FormRequest
{
    use ApiResponses;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('idempotency_key') && $this->headers->has('Idempotency-Key')) {
            $this->merge([
                'idempotency_key' => $this->header('Idempotency-Key'),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'order_id' => ['required', 'integer', 'min:1', 'exists:orders,id'],
            'idempotency_key' => ['required', 'string', 'min:3', 'max:100'],
            'outcome' => ['sometimes', 'string', 'min:3', 'in:succeeded,failed'],
            'event_id' => ['sometimes', 'nullable', 'string', 'min:3', 'max:100'],
            'failure_reason' => ['sometimes', 'nullable', 'string', 'min:3', 'max:255'],
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            $this->validationErrorResponse(
                $validator->errors()->toArray(),
                $validator->errors()->first() ?: __('api.validation_failed')
            )
        );
    }
}
