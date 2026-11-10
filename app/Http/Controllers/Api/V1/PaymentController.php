<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransitionException;
use App\Exceptions\MissingActiveReservationException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\ProcessPaymentEventRequest;
use App\Http\Requests\Api\SimulatePaymentRequest;
use App\Http\Resources\Api\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class PaymentController extends ApiController
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    /**
     * Simulate and persist a payment attempt for an order.
     */
    public function store(SimulatePaymentRequest $request): JsonResponse
    {
        try {
            $order = Order::findOrFail((int) $request->validated('order_id'));
            $outcome = (string) ($request->validated('outcome') ?? PaymentStatus::Succeeded->value);
            $succeed = $outcome === PaymentStatus::Succeeded->value;

            $result = $this->paymentService->process(
                order: $order,
                idempotencyKey: (string) $request->validated('idempotency_key'),
                succeed: $succeed,
                eventId: $request->validated('event_id'),
                failureReason: $request->validated('failure_reason'),
                actor: $request->user()
            );

            $payment = $result['payment'];

            if (! $result['is_new']) {
                return $this->successResponse(
                    new PaymentResource($payment),
                    __('api.payment_retrieved_successfully'),
                    200
                );
            }

            if ($payment->status === PaymentStatus::Failed) {
                return $this->createdResponse(
                    new PaymentResource($payment),
                    __('api.payment_failed')
                );
            }

            return $this->createdResponse(
                new PaymentResource($payment),
                __('api.payment_processed_successfully')
            );
        } catch (InvalidArgumentException $e) {
            return $this->badRequestResponse($e->getMessage());
        } catch (InvalidOrderTransitionException|MissingActiveReservationException $e) {
            return $this->conflictResponse($e->getMessage());
        }
    }

    /**
     * Process a simulated payment callback/webhook event idempotently.
     */
    public function processEvent(ProcessPaymentEventRequest $request): JsonResponse
    {
        try {
            $payment = Payment::findOrFail((int) $request->validated('payment_id'));
            $outcomeStatus = PaymentStatus::from((string) $request->validated('outcome'));

            $result = $this->paymentService->processEvent(
                payment: $payment,
                eventId: (string) $request->validated('event_id'),
                outcomeStatus: $outcomeStatus,
                failureReason: $request->validated('failure_reason'),
                payload: (array) ($request->validated('payload') ?? []),
                actor: $request->user()
            );

            return $this->successResponse(
                new PaymentResource($result['payment']),
                __('api.payment_event_processed_successfully'),
                200
            );
        } catch (InvalidArgumentException $e) {
            return $this->badRequestResponse($e->getMessage());
        } catch (InvalidOrderTransitionException|MissingActiveReservationException $e) {
            return $this->conflictResponse($e->getMessage());
        }
    }
}
