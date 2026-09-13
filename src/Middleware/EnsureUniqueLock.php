<?php

namespace BoringO11y\UniqueJobMiddleware\Middleware;

use BoringO11y\UniqueJobMiddleware\Events\UniqueJobSkipped;
use BoringO11y\UniqueJobMiddleware\LockRecord;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Jobs\Job;
use Throwable;

/**
 * Checks a unique job's lock again when a worker picks it up, and skips the job
 * if another job holds it.
 *
 * Only a dispatch refuses a unique job whose lock is taken. The first job of a
 * chain, a batched job, a direct Queue::push() and a retry are queued anyway,
 * and without this middleware such a copy runs alongside the job holding the
 * lock.
 *
 * The job runs when it holds its lock, or when the lock is free, in which case
 * it takes the lock first: under the owner its payload records, or under its
 * uuid when it records none, so a later attempt of the same job still counts
 * as the holder. It is skipped when another owner holds the lock.
 *
 * Before Laravel 11 moved it, the framework released a lock unique until
 * processing before middleware runs, so on those versions such a job always
 * finds its lock free and runs.
 *
 * A job with no record in its payload (queued before the package was
 * installed) always runs, as does one whose lock cannot be read: a job is only
 * skipped on proof.
 *
 *     public function middleware(): array
 *     {
 *         return [new EnsureUniqueLock];
 *     }
 */
class EnsureUniqueLock
{
    /**
     * Process the job.
     *
     * @param  mixed  $command
     * @param  callable  $next
     * @return mixed
     */
    public function handle($command, $next)
    {
        $job = $command->job ?? null;

        // Skipping sets a flag only the framework's own queue jobs carry.
        if (! $job instanceof Job || ! LockRecord::unique($command)) {
            return $next($command);
        }

        $payload = $job->payload();

        if (! $record = LockRecord::fromPayload($payload)) {
            return $next($command);
        }

        $owner = $record['owner'] ?? $payload['uuid'] ?? $payload['id'] ?? null;

        try {
            $held = $this->holdsLock($command, $record['key'], $owner);
        } catch (Throwable $e) {
            return $next($command);
        }

        if ($held) {
            return $next($command);
        }

        $this->skip($command, $job, $record['key']);
    }

    /**
     * Determine whether the job holds its lock, taking it if it is free.
     *
     * A lock unique until processing is released as the job starts, so a free
     * one is not taken: on versions that release it before middleware runs,
     * nothing would release it again.
     *
     * @param  object  $command
     * @param  string  $key
     * @param  string|null  $owner
     * @return bool
     */
    protected function holdsLock($command, string $key, $owner)
    {
        $cache = LockRecord::cache($command);
        $current = LockRecord::currentOwner($cache, $key);

        if ($current === null && LockRecord::uniqueUntilProcessing($command)) {
            return true;
        }

        if (! is_string($owner)) {
            return false;
        }

        if ($current === $owner) {
            return true;
        }

        return $current === null
            && (bool) $cache->getStore()->lock($key, LockRecord::uniqueFor($command), $owner)->get();
    }

    /**
     * Delete the job without running it or releasing the lock another job holds.
     *
     * @param  object  $command
     * @param  \Illuminate\Queue\Jobs\Job  $job
     * @param  string  $key
     * @return void
     */
    protected function skip($command, Job $job, string $key)
    {
        // A batch counts the job when it is added, so it would wait forever on
        // one that never reports. The rest of a chain is not dispatched, as when
        // a chained unique job's own dispatch is discarded.
        if (method_exists($command, 'batch') && $batch = $command->batch()) {
            $batch->recordSuccessfulJob($job->uuid());
        }

        $job->delete();

        // CallQueuedHandler force-releases a unique job's lock once middleware
        // returns, unless the job was released, and that lock is another job's.
        // Marking the job released stops the release (and the chain and batch
        // bookkeeping handled above) without queueing it again.
        (fn () => $this->released = true)->call($job);

        Container::getInstance()->make(Dispatcher::class)->dispatch(
            new UniqueJobSkipped($job, $command, $key)
        );
    }
}
