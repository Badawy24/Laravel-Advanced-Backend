<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Traits\ApiResponses;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ProcessPaymentEventRequest extends FormRequest
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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'payment_id' => ['required', 'integer', 'min:1', 'exists:payments,id'],
            'event_id' => ['required', 'string', 'min:3', 'max:100'],
            'outcome' => ['required', 'string', 'min:3', 'in:succeeded,failed'],
            'failure_reason' => ['sometimes', 'nullable', 'string', 'min:3', 'max:255'],
            'payload' => ['sometimes', 'array'],
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
