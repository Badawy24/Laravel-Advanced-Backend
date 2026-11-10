<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Jobs\ReconcileSucceededPayment;
use App\Models\Payment;
use Illuminate\Console\Command;

class ReconcileSucceededPaymentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:reconcile';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Discover succeeded payments with unapplied order updates and dispatch reconciliation jobs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $paymentIds = Payment::query()
            ->where('status', PaymentStatus::Succeeded)
            ->whereNull('applied_at')
            ->orderBy('id', 'asc')
            ->pluck('id');

        foreach ($paymentIds as $paymentId) {
            ReconcileSucceededPayment::dispatch((int) $paymentId);
        }

        return self::SUCCESS;
    }
}
