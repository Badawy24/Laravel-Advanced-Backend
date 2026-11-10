<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileSucceededPayment implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $paymentId
    ) {
        $this->afterCommit();
    }

    /**
     * Execute the job.
     */
    public function handle(PaymentService $paymentService): void
    {
        $payment = Payment::find($this->paymentId);

        if ($payment === null) {
            return;
        }

        $paymentService->reconcile($payment);
    }
}
