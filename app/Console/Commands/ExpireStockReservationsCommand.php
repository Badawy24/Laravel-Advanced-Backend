<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\StockReservationStatus;
use App\Jobs\ExpireStockReservation;
use App\Models\StockReservation;
use Illuminate\Console\Command;

class ExpireStockReservationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reservations:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Discover expired active stock reservations and dispatch expiration jobs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $reservationIds = StockReservation::query()
            ->where('status', StockReservationStatus::Active)
            ->whereNull('resolved_at')
            ->where('expires_at', '<=', now())
            ->whereHas('orderItem.order', function ($query): void {
                $query->where('status', OrderStatus::Pending);
            })
            ->orderBy('id', 'asc')
            ->pluck('id');

        foreach ($reservationIds as $reservationId) {
            ExpireStockReservation::dispatch((int) $reservationId);
        }

        return self::SUCCESS;
    }
}

