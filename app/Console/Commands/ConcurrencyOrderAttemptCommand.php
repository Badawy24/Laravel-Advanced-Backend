<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class ConcurrencyOrderAttemptCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'order:concurrency-attempt {user_id} {branch_id} {product_id} {quantity}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulate a concurrent order request for concurrency testing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::findOrFail((int) $this->argument('user_id'));
        $token = $user->createToken('test')->plainTextToken;

        $request = Request::create(
            '/api/v1/orders',
            'POST',
            [
                'branch_id' => (int) $this->argument('branch_id'),
                'items' => [
                    [
                        'product_id' => (int) $this->argument('product_id'),
                        'quantity' => (int) $this->argument('quantity'),
                    ],
                ],
            ],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_APP_ACCESS_KEY' => (string) config('api.key'),
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ]
        );

        $response = app()->handle($request);
        $this->line((string) $response->getStatusCode());

        return 0;
    }
}
