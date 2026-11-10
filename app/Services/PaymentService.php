<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransitionException;
use App\Exceptions\MissingActiveReservationException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PaymentService
{
    public function __construct(
        protected OrderService $orderService
    ) {}

    /**
     * Simulate and persist a payment attempt for an order, then apply order status updates on success.
     *
     * @return array{payment: Payment, event: PaymentEvent|null, is_new: bool}
     *
     * @throws InvalidArgumentException
     * @throws InvalidOrderTransitionException
     * @throws MissingActiveReservationException
     */
    public function process(
        Order $order,
        string $idempotencyKey,
        bool $succeed = true,
        ?string $eventId = null,
        ?string $failureReason = null,
        User|int|null $actor = null
    ): array {
        $normalizedKey = trim($idempotencyKey);
        if ($normalizedKey === '') {
            throw new InvalidArgumentException(__('api.payment_idempotency_key_required'));
        }

        $existingPayment = Payment::where('idempotency_key', $normalizedKey)
            ->with(['events', 'order'])
            ->first();

        if ($existingPayment !== null) {
            return $this->resolveExistingPayment($existingPayment, $actor);
        }

        $resolvedEventId = $eventId !== null && trim($eventId) !== ''
            ? trim($eventId)
            : 'evt_'.Str::lower((string) Str::ulid());

        try {
            /** @var array{payment: Payment, event: PaymentEvent, is_new: bool} $recorded */
            $recorded = DB::transaction(function () use ($order, $normalizedKey, $succeed, $resolvedEventId, $failureReason): array {
                /** @var Order $lockedOrder */
                $lockedOrder = Order::whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $concurrentPayment = Payment::where('idempotency_key', $normalizedKey)
                    ->lockForUpdate()
                    ->first();

                if ($concurrentPayment !== null) {
                    $existingEvent = PaymentEvent::where('payment_id', $concurrentPayment->id)
                        ->orderBy('id', 'desc')
                        ->first();

                    return [
                        'payment' => $concurrentPayment,
                        'event' => $existingEvent,
                        'is_new' => false,
                    ];
                }

                if ($lockedOrder->status !== OrderStatus::Pending) {
                    throw new InvalidOrderTransitionException;
                }

                $alreadySucceededForOrder = Payment::where('order_id', $lockedOrder->id)
                    ->where('status', PaymentStatus::Succeeded)
                    ->lockForUpdate()
                    ->exists();

                if ($alreadySucceededForOrder) {
                    throw new InvalidOrderTransitionException;
                }

                $status = $succeed ? PaymentStatus::Succeeded : PaymentStatus::Failed;
                $resolvedFailureReason = $succeed ? null : ($failureReason ?? 'Simulated payment failure');

                $payment = Payment::create([
                    'order_id' => $lockedOrder->id,
                    'idempotency_key' => $normalizedKey,
                    'amount' => $lockedOrder->total_amount,
                    'currency' => $lockedOrder->currency,
                    'status' => $status,
                    'failure_reason' => $resolvedFailureReason,
                    'provider_reference' => 'sim_pay_'.Str::lower((string) Str::ulid()),
                    'applied_at' => null,
                ]);

                $event = PaymentEvent::create([
                    'payment_id' => $payment->id,
                    'event_id' => $resolvedEventId,
                    'type' => $succeed ? 'payment.succeeded' : 'payment.failed',
                    'payload' => [
                        'order_id' => $lockedOrder->id,
                        'payment_id' => $payment->id,
                        'amount' => (string) $lockedOrder->total_amount,
                        'currency' => $lockedOrder->currency,
                        'status' => $status->value,
                        'result' => $succeed ? 'pending_order_update' : 'failed_recorded',
                        'failure_reason' => $resolvedFailureReason,
                    ],
                    'processed_at' => $succeed ? null : now(),
                    'created_at' => now(),
                ]);

                return [
                    'payment' => $payment,
                    'event' => $event,
                    'is_new' => true,
                ];
            }, 3);
        } catch (UniqueConstraintViolationException|QueryException $e) {
            $existingPayment = Payment::where('idempotency_key', $normalizedKey)
                ->with(['events', 'order'])
                ->first();

            if ($existingPayment !== null) {
                return $this->resolveExistingPayment($existingPayment, $actor);
            }

            throw $e;
        }

        if (! $recorded['is_new']) {
            return $this->resolveExistingPayment($recorded['payment'], $actor);
        }

        $payment = $recorded['payment'];
        $event = $recorded['event'];

        if ($payment->status === PaymentStatus::Succeeded) {
            $payment = $this->reconcile($payment, $actor);
        }

        return [
            'payment' => $payment->fresh(['events', 'order']),
            'event' => $event?->fresh(),
            'is_new' => true,
        ];
    }

    /**
     * Initiate a pending payment attempt for an order using server-side order totals.
     *
     * @return array{payment: Payment, is_new: bool}
     *
     * @throws InvalidArgumentException
     * @throws InvalidOrderTransitionException
     */
    public function initiate(Order $order, string $idempotencyKey): array
    {
        $normalizedKey = trim($idempotencyKey);
        if ($normalizedKey === '') {
            throw new InvalidArgumentException(__('api.payment_idempotency_key_required'));
        }

        $existingPayment = Payment::where('idempotency_key', $normalizedKey)
            ->with(['events', 'order'])
            ->first();

        if ($existingPayment !== null) {
            if ($existingPayment->status === PaymentStatus::Succeeded && $existingPayment->applied_at === null) {
                $this->reconcile($existingPayment);
            }

            return [
                'payment' => $existingPayment->fresh(['events', 'order']),
                'is_new' => false,
            ];
        }

        try {
            return DB::transaction(function () use ($order, $normalizedKey): array {
                /** @var Order $lockedOrder */
                $lockedOrder = Order::whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedOrder->status !== OrderStatus::Pending) {
                    throw new InvalidOrderTransitionException;
                }

                $alreadySucceededForOrder = Payment::where('order_id', $lockedOrder->id)
                    ->where('status', PaymentStatus::Succeeded)
                    ->lockForUpdate()
                    ->exists();

                if ($alreadySucceededForOrder) {
                    throw new InvalidOrderTransitionException;
                }

                $payment = Payment::create([
                    'order_id' => $lockedOrder->id,
                    'idempotency_key' => $normalizedKey,
                    'amount' => $lockedOrder->total_amount,
                    'currency' => $lockedOrder->currency,
                    'status' => PaymentStatus::Pending,
                    'failure_reason' => null,
                    'provider_reference' => 'sim_pay_'.Str::lower((string) Str::ulid()),
                    'applied_at' => null,
                ]);

                return [
                    'payment' => $payment->fresh(['events', 'order']),
                    'is_new' => true,
                ];
            }, 3);
        } catch (UniqueConstraintViolationException|QueryException $e) {
            $existingPayment = Payment::where('idempotency_key', $normalizedKey)
                ->with(['events', 'order'])
                ->first();

            if ($existingPayment !== null) {
                return [
                    'payment' => $existingPayment,
                    'is_new' => false,
                ];
            }

            throw $e;
        }
    }

    /**
     * Process a simulated payment callback/webhook event idempotently.
     *
     * @param  array<string, mixed>  $payload
     * @return array{payment: Payment, event: PaymentEvent, is_duplicate: bool}
     *
     * @throws InvalidArgumentException
     * @throws InvalidOrderTransitionException
     * @throws MissingActiveReservationException
     */
    public function processEvent(
        Payment $payment,
        string $eventId,
        PaymentStatus $outcomeStatus,
        ?string $failureReason = null,
        array $payload = [],
        User|int|null $actor = null
    ): array {
        $normalizedEventId = trim($eventId);
        if ($normalizedEventId === '') {
            throw new InvalidArgumentException(__('api.payment_event_id_required'));
        }

        if ($outcomeStatus === PaymentStatus::Pending) {
            throw new InvalidArgumentException('Payment event outcome must be succeeded or failed.');
        }

        $existingEvent = PaymentEvent::where('event_id', $normalizedEventId)
            ->with('payment')
            ->first();

        if ($existingEvent !== null) {
            return $this->resolveExistingEvent($existingEvent, $actor);
        }

        try {
            /** @var array{payment: Payment, event: PaymentEvent, is_duplicate: bool} $recorded */
            $recorded = DB::transaction(function () use ($payment, $normalizedEventId, $outcomeStatus, $failureReason, $payload): array {
                /** @var Order $lockedOrder */
                $lockedOrder = Order::whereKey($payment->order_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /** @var Payment $lockedPayment */
                $lockedPayment = Payment::whereKey($payment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $concurrentEvent = PaymentEvent::where('event_id', $normalizedEventId)
                    ->lockForUpdate()
                    ->first();

                if ($concurrentEvent !== null) {
                    return [
                        'payment' => $lockedPayment,
                        'event' => $concurrentEvent,
                        'is_duplicate' => true,
                    ];
                }

                $succeed = $outcomeStatus === PaymentStatus::Succeeded;
                $eventType = $succeed ? 'payment.succeeded' : 'payment.failed';

                if ($lockedPayment->status === PaymentStatus::Pending) {
                    if ($lockedOrder->status !== OrderStatus::Pending) {
                        throw new InvalidOrderTransitionException;
                    }

                    $lockedPayment->status = $outcomeStatus;
                    $lockedPayment->failure_reason = $succeed ? null : ($failureReason ?? 'Simulated payment failure');
                    $lockedPayment->provider_reference ??= 'sim_pay_'.Str::lower((string) Str::ulid());
                    $lockedPayment->save();

                    $resultState = $succeed ? 'pending_order_update' : 'failed_recorded';
                } else {
                    $resultState = 'ignored_already_resolved';
                }

                $event = PaymentEvent::create([
                    'payment_id' => $lockedPayment->id,
                    'event_id' => $normalizedEventId,
                    'type' => $eventType,
                    'payload' => array_merge($payload, [
                        'order_id' => $lockedOrder->id,
                        'payment_id' => $lockedPayment->id,
                        'amount' => (string) $lockedPayment->amount,
                        'currency' => $lockedPayment->currency,
                        'status' => $lockedPayment->status->value,
                        'result' => $resultState,
                        'failure_reason' => $lockedPayment->failure_reason,
                    ]),
                    'processed_at' => ($lockedPayment->status === PaymentStatus::Succeeded && $lockedPayment->applied_at === null)
                        ? null
                        : now(),
                    'created_at' => now(),
                ]);

                return [
                    'payment' => $lockedPayment,
                    'event' => $event,
                    'is_duplicate' => false,
                ];
            }, 3);
        } catch (UniqueConstraintViolationException|QueryException $e) {
            $existingEvent = PaymentEvent::where('event_id', $normalizedEventId)
                ->with('payment')
                ->first();

            if ($existingEvent !== null) {
                return $this->resolveExistingEvent($existingEvent, $actor);
            }

            throw $e;
        }

        if ($recorded['is_duplicate']) {
            return $this->resolveExistingEvent($recorded['event'], $actor);
        }

        $updatedPayment = $recorded['payment'];
        $event = $recorded['event'];

        if ($updatedPayment->status === PaymentStatus::Succeeded && $updatedPayment->applied_at === null) {
            $updatedPayment = $this->reconcile($updatedPayment, $actor);
        }

        return [
            'payment' => $updatedPayment->fresh(['events', 'order']),
            'event' => $event->fresh(),
            'is_duplicate' => false,
        ];
    }

    /**
     * Reconcile a succeeded payment that has not yet been applied to its order.
     *
     * @throws InvalidOrderTransitionException
     * @throws MissingActiveReservationException
     */
    public function reconcile(Payment $payment, User|int|null $actor = null): Payment
    {
        return DB::transaction(function () use ($payment, $actor): Payment {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::whereKey($payment->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->status !== PaymentStatus::Succeeded || $lockedPayment->applied_at !== null) {
                return $lockedPayment;
            }

            if ($lockedOrder->status === OrderStatus::Pending) {
                $this->orderService->confirm(
                    $lockedOrder,
                    $actor,
                    'Order confirmed after successful payment'
                );
            } elseif ($lockedOrder->status !== OrderStatus::Confirmed) {
                throw new InvalidOrderTransitionException;
            }

            $appliedAt = now();
            $lockedPayment->applied_at = $appliedAt;
            $lockedPayment->save();

            $unprocessedEvents = PaymentEvent::where('payment_id', $lockedPayment->id)
                ->whereNull('processed_at')
                ->lockForUpdate()
                ->get();

            foreach ($unprocessedEvents as $event) {
                $payload = is_array($event->payload) ? $event->payload : [];
                $payload['result'] = 'order_confirmed';
                $event->payload = $payload;
                $event->processed_at = $appliedAt;
                $event->save();
            }

            return $lockedPayment;
        }, 3);
    }

    /**
     * Discover and reconcile all succeeded payments that have not yet updated their orders.
     */
    public function reconcileUnappliedPayments(): int
    {
        $unappliedPayments = Payment::where('status', PaymentStatus::Succeeded)
            ->whereNull('applied_at')
            ->orderBy('id', 'asc')
            ->get();

        $reconciledCount = 0;

        foreach ($unappliedPayments as $payment) {
            $reconciled = $this->reconcile($payment);
            if ($reconciled->applied_at !== null) {
                $reconciledCount++;
            }
        }

        return $reconciledCount;
    }

    /**
     * Resolve an existing payment found by idempotency key and ensure any pending reconciliation completes.
     *
     * @return array{payment: Payment, event: PaymentEvent|null, is_new: bool}
     */
    protected function resolveExistingPayment(Payment $existingPayment, User|int|null $actor = null): array
    {
        if ($existingPayment->status === PaymentStatus::Succeeded && $existingPayment->applied_at === null) {
            $existingPayment = $this->reconcile($existingPayment, $actor);
        }

        $freshPayment = $existingPayment->fresh(['events', 'order']);

        return [
            'payment' => $freshPayment,
            'event' => $freshPayment->events->last(),
            'is_new' => false,
        ];
    }

    /**
     * Resolve an existing payment event found by event ID and ensure any pending reconciliation completes.
     *
     * @return array{payment: Payment, event: PaymentEvent, is_duplicate: bool}
     */
    protected function resolveExistingEvent(PaymentEvent $existingEvent, User|int|null $actor = null): array
    {
        $payment = $existingEvent->payment;

        if ($payment->status === PaymentStatus::Succeeded && $payment->applied_at === null) {
            $payment = $this->reconcile($payment, $actor);
        }

        if ($existingEvent->processed_at === null) {
            $existingEvent->processed_at = now();
            $existingEvent->save();
        }

        return [
            'payment' => $payment->fresh(['events', 'order']),
            'event' => $existingEvent->fresh(),
            'is_duplicate' => true,
        ];
    }
}
