<?php

namespace BoringO11y\UniqueJobMiddleware;

use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Foundation\Bus\PendingDispatch;
use ReflectionMethod;
use Throwable;

/**
 * Records in a unique job's payload which lock owner, if any, it was queued under.
 *
 * The framework takes a unique job's lock with a random owner token and
 * discards it, and only a dispatch takes the lock at all: the first job of a
 * chain, a batched job and a direct Queue::push() are queued without one, even
 * while another job holds it. A push made from inside a dispatch that just took
 * the lock records that lock's owner; any other push records no owner. It
 * travels in the payload as "uniqueJobLock" => ['key' => ..., 'owner' => ...|null],
 * and a retry re-queues the payload with the record unchanged.
 */
class LockRecord
{
    /**
     * The payload key the record is stored under.
     *
     * @var string
     */
    public const PAYLOAD_KEY = 'uniqueJobLock';

    /**
     * Build the record for a payload being created, as a queue payload hook.
     *
     * The hook runs before the command is serialized, so the payload still
     * carries the job instance. A failure never breaks the push: the job is
     * queued without a record, and the middleware lets it run.
     *
     * @param  string|null  $connection
     * @param  string|null  $queue
     * @param  array  $payload
     * @return array
     */
    public static function forPayload($connection, $queue, array $payload)
    {
        $job = $payload['data']['command'] ?? null;

        if (! is_object($job) || ! static::unique($job)) {
            return [];
        }

        try {
            if (is_null($key = static::key($job))) {
                return [];
            }

            $owner = static::pushedByAcquiringDispatch($job)
                ? static::currentOwner(static::cache($job), $key)
                : null;
        } catch (Throwable $e) {
            return [];
        }

        return [static::PAYLOAD_KEY => ['key' => $key, 'owner' => $owner]];
    }

    /**
     * Get the record from a job's payload.
     *
     * @param  array  $payload
     * @return array{key: string, owner: string|null}|null
     */
    public static function fromPayload(array $payload)
    {
        $record = $payload[static::PAYLOAD_KEY] ?? null;

        if (! is_array($record) || ! is_string($record['key'] ?? null)) {
            return null;
        }

        return ['key' => $record['key'], 'owner' => is_string($record['owner'] ?? null) ? $record['owner'] : null];
    }

    /**
     * Determine if the command is unique.
     *
     * Queued listeners gained unique support later, so the method a
     * CallQueuedListener carries may be missing.
     *
     * @param  mixed  $command
     * @return bool
     */
    public static function unique($command)
    {
        return $command instanceof ShouldBeUnique ||
            ($command instanceof CallQueuedListener &&
                method_exists($command, 'shouldBeUnique') &&
                $command->shouldBeUnique());
    }

    /**
     * Determine if the command's unique lock is released once processing starts.
     *
     * @param  mixed  $command
     * @return bool
     */
    public static function uniqueUntilProcessing($command)
    {
        return $command instanceof ShouldBeUniqueUntilProcessing ||
            ($command instanceof CallQueuedListener &&
                method_exists($command, 'shouldBeUniqueUntilProcessing') &&
                $command->shouldBeUniqueUntilProcessing());
    }

    /**
     * Get the command's unique lock key, as the installed framework builds it.
     *
     * UniqueLock::getKey() is a public static method only on recent versions,
     * and its format has changed, so it is called rather than reproduced.
     *
     * @param  object  $command
     * @return string|null
     */
    public static function key($command)
    {
        try {
            $method = new ReflectionMethod(UniqueLock::class, 'getKey');
            $method->setAccessible(true);

            $key = $method->invoke($method->isStatic()
                ? null
                : new UniqueLock(Container::getInstance()->make(Cache::class)), $command);
        } catch (Throwable $e) {
            return null;
        }

        return is_string($key) ? $key : null;
    }

    /**
     * Get the cache repository the command's unique lock is kept in, as UniqueLock does.
     *
     * @param  object  $command
     * @return \Illuminate\Contracts\Cache\Repository
     */
    public static function cache($command)
    {
        $default = Container::getInstance()->make(Cache::class);

        return method_exists($command, 'uniqueVia')
            ? ($command->uniqueVia() ?? $default)
            : $default;
    }

    /**
     * Get the number of seconds the command's lock is held for, as UniqueLock does.
     *
     * @param  object  $command
     * @return int
     */
    public static function uniqueFor($command)
    {
        return (int) (method_exists($command, 'uniqueFor')
            ? $command->uniqueFor()
            : ($command->uniqueFor ?? 0));
    }

    /**
     * Get the owner token the lock is currently held by, if it is held.
     *
     * @param  \Illuminate\Contracts\Cache\Repository  $cache
     * @param  string  $key
     * @return string|null
     */
    public static function currentOwner($cache, string $key)
    {
        // Straight from the store: a repository may hand out a decorated lock
        // that hides getCurrentOwner().
        $lock = $cache->getStore()->lock($key);

        $owner = (fn () => $this->getCurrentOwner())->call($lock);

        return is_string($owner) && $owner !== '' ? $owner : null;
    }

    /**
     * Determine whether the job is being pushed by a dispatch that just took its lock.
     *
     * Those are PendingDispatch (dispatch(), Job::dispatch()), the event
     * dispatcher queueing a unique listener, and the scheduler queueing a
     * unique job. Each acquires the lock and pushes in the same call, and the
     * payload is built eagerly even when the push waits for a commit.
     *
     * @param  object  $job
     * @return bool
     */
    protected static function pushedByAcquiringDispatch($job)
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 40) as $frame) {
            $object = $frame['object'] ?? null;
            $function = $frame['function'] ?? null;

            if ($object instanceof PendingDispatch && $function === '__destruct') {
                return (fn () => $this->job)->call($object) === $job;
            }

            if ($object instanceof EventDispatcher && $function === 'queueHandler') {
                return $job instanceof CallQueuedListener && ($frame['args'][0] ?? null) === $job->class;
            }

            if ($object instanceof Schedule && $function === 'dispatchUniqueJobToQueue') {
                return ($frame['args'][0] ?? null) === $job;
            }
        }

        return false;
    }
}
