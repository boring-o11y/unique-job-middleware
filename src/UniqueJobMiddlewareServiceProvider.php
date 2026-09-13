<?php

namespace BoringO11y\UniqueJobMiddleware;

use Illuminate\Queue\Queue;
use Illuminate\Support\ServiceProvider;

class UniqueJobMiddlewareServiceProvider extends ServiceProvider
{
    /**
     * Record the lock owner in every unique job's payload.
     *
     * Registered on every boot: Laravel's test lifecycle clears payload hooks
     * after each test, while the application is booted afresh for the next.
     *
     * @return void
     */
    public function boot()
    {
        Queue::createPayloadUsing([LockRecord::class, 'forPayload']);
    }
}
