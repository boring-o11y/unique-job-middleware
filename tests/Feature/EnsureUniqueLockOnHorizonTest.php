<?php

namespace BoringO11y\UniqueJobMiddleware\Tests\Feature;

use BoringO11y\UniqueJobMiddleware\UniqueJobMiddlewareServiceProvider;
use Laravel\Horizon\HorizonServiceProvider;
use Laravel\Horizon\RedisQueue;
use Illuminate\Support\Facades\Queue;

/**
 * The same behaviour through Horizon's queue driver, which replaces the redis connector.
 */
class EnsureUniqueLockOnHorizonTest extends EnsureUniqueLockTest
{
    protected function getPackageProviders($app)
    {
        return [HorizonServiceProvider::class, UniqueJobMiddlewareServiceProvider::class];
    }

    public function test_the_queue_is_horizons()
    {
        $this->assertInstanceOf(RedisQueue::class, Queue::connection('redis'));
    }
}
