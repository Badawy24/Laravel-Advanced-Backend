<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Console\Command;
use Throwable;

class ConcurrencyOrderCancelAttemptCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'order:concurrency-cancel {order_id} {user_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulate a concurrent order cancellation attempt for concurrency testing';

    /**
     * Execute the console command.
     */
    public function handle(OrderService $orderService): int
    {
        $order = Order::findOrFail((int) $this->argument('order_id'));
        $userId = $this->argument('user_id') !== null ? (int) $this->argument('user_id') : null;

        try {
            $orderService->cancel($order, $userId, 'Concurrent cancellation attempt');
            $this->line('CANCELLED');
        } catch (InvalidOrderTransitionException) {
            $this->line('REJECTED');
        } catch (Throwable $e) {
            $this->line('ERROR:'.$e->getMessage());
        }

        return 0;
    }
}
