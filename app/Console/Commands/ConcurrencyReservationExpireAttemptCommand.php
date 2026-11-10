<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ExpireStockReservation;
use App\Services\OrderService;
use Illuminate\Console\Command;
use Throwable;

class ConcurrencyReservationExpireAttemptCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'order:concurrency-expire {reservation_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulate a concurrent stock reservation expiration attempt for concurrency testing';

    /**
     * Execute the console command.
     */
    public function handle(OrderService $orderService): int
    {
        $reservationId = (int) $this->argument('reservation_id');

        try {
            $job = new ExpireStockReservation($reservationId);
            $job->handle($orderService);
            $this->line('PROCESSED');
        } catch (Throwable $e) {
            $this->line('ERROR:'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}

