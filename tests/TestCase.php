<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        Connection::resolverFor('sqlite', function ($connection, $database, $prefix, $config) {
            return new class($connection, $database, $prefix, $config) extends SQLiteConnection
            {
                public function statement($query, $bindings = []): bool
                {
                    if (stripos($query, 'ALTER TABLE') !== false && stripos($query, 'ADD CONSTRAINT') !== false) {
                        return true;
                    }

                    return parent::statement($query, $bindings);
                }
            };
        });
    }
}
