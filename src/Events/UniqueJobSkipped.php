<?php

namespace BoringO11y\UniqueJobMiddleware\Events;

use Illuminate\Queue\Jobs\Job;

/**
 * A unique job was deleted without running because another job holds its lock.
 */
class UniqueJobSkipped
{
    /**
     * Create a new event instance.
     *
     * @param  \Illuminate\Queue\Jobs\Job  $job  The queue job that was deleted.
     * @param  object  $command  The job instance that did not run.
     * @param  string  $lockKey  The unique lock another job holds.
     * @return void
     */
    public function __construct(
        public Job $job,
        public object $command,
        public string $lockKey,
    ) {
    }
}
