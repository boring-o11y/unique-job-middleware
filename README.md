# Unique Job Middleware

A queue job middleware that checks a `ShouldBeUnique` job's lock again when a worker picks it up, and skips the job if another job holds it.

```php
use BoringO11y\UniqueJobMiddleware\Middleware\EnsureUniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

class RecalculateInvoice implements ShouldQueue, ShouldBeUnique
{
    public function __construct(public int $invoiceId) {}

    public function uniqueId(): string
    {
        return (string) $this->invoiceId;
    }

    public function middleware(): array
    {
        return [new EnsureUniqueLock];
    }
}
```

## Why

Laravel checks uniqueness in exactly one place: `dispatch()`. It takes the lock there, and if the lock is already held the dispatch is quietly discarded. Every other way onto the queue skips that check:

- the first job of a `Bus::chain()`
- jobs in a `Bus::batch()`
- `Queue::push()`, `Queue::later()` and `Queue::bulk()`
- `queue:retry` and Horizon's retry button
- `Bus::dispatch()` / `Bus::dispatchToQueue()`

A unique job queued any of those ways runs even while another copy holds the lock, so two copies run side by side.

The middleware can't just check "is the lock held?". A normally dispatched job holds its own lock while it waits, so the middleware has to know whether the lock is *this job's*. Laravel takes the lock with a random owner token and throws the token away. This package records it in the payload so the middleware can compare.

## Install

```bash
composer require boring-o11y/unique-job-middleware
```

The service provider is discovered automatically. Then add `new EnsureUniqueLock` to the `middleware()` of each unique job you want protected.

## What happens when the job is picked up

| The job's unique lock is… | Result |
|---|---|
| held by this job | runs |
| free | takes the lock, then runs |
| held by another job | deleted without running; `UniqueJobSkipped` is fired |
| unreadable (store has no locks, Redis error) | runs |
| not recorded (job queued before install) | runs |

A job is only skipped when there is proof another job holds its lock.

When a job takes a free lock, it takes it under the owner its payload records, or under its own uuid if it has none. If it is released back onto the queue, its next attempt still counts as the holder. For `ShouldBeUnique` jobs Laravel releases that lock when the job finishes, just as for a dispatched job.

### Chains and batches

- **Chains:** a skipped job ends its chain. The jobs after it are not dispatched. That's also what Laravel does when a chained unique job's own dispatch is discarded.
- **Batches:** a skipped job counts as a success for its batch, so the batch doesn't wait forever for a job that will never report.

### `ShouldBeUniqueUntilProcessing`

These jobs release their lock as they start, so the middleware never takes a free lock for them. It only skips a copy while another job still holds the lock.

On Laravel 10 and early 11.x, the framework releases that lock *before* middleware runs. An until-processing job therefore always finds its lock free there and runs. Plain `ShouldBeUnique` jobs are protected on every supported version.

## Seeing skipped jobs

A skip is silent unless you listen for it. In Horizon, a skipped job shows as completed.

```php
use BoringO11y\UniqueJobMiddleware\Events\UniqueJobSkipped;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(function (UniqueJobSkipped $event) {
    Log::warning('Skipped a duplicate unique job', [
        'job' => $event->job->resolveName(),
        'job_id' => $event->job->uuid(),
        'lock' => $event->lockKey,
    ]);
});
```

The event carries the queue job (`$job`), the job instance that didn't run (`$command`), and the lock key (`$lockKey`).

## How it works

**At push.** A `Queue::createPayloadUsing()` hook adds a record to the payload of every unique job:

```json
"uniqueJobLock": {"key": "laravel_unique_job:App\\Jobs\\RecalculateInvoice:42", "owner": "Hx9…"}
```

The hook runs before the job is serialized, so it sees the job instance. `owner` is filled in only when the push happens inside a dispatch that just took the lock: `PendingDispatch`, a unique queued listener, or the scheduler queueing a unique job. It is found with a backtrace, and only for unique jobs. Every other push records `owner: null`. A retry re-queues the stored payload, so the record comes with it.

**At pickup.** The middleware reads the record and compares it with the lock's current owner in the cache store.

**On skip.** Returning from middleware without calling `$next` isn't enough on its own. After middleware returns, Laravel's `CallQueuedHandler` force-releases the unique lock by key, and that lock belongs to the *other* job. So the middleware:

1. deletes the job
2. marks the queue job as released, which stops that release without putting the job back on the queue

That marker is a protected property on `Illuminate\Queue\Jobs\Job`, which every built-in queue driver's job extends.

## Limits

- **Locks that expire.** If `uniqueFor` runs out while a job waits and another dispatch takes the key, the older job is skipped and the newer one does the work.
- **Other code that force-releases by key.** Laravel's own unique-lock releases (a job failing, a model-not-found) are ownerless. The middleware stops only the release that follows a skip.
- **Custom queue job classes** that don't extend `Illuminate\Queue\Jobs\Job` pass straight through.

## Requirements

PHP 8.1+, Laravel 10–13. Works with any queue driver, and with Laravel Horizon.

## Testing

```bash
docker compose run --rm app composer install
docker compose run --rm app vendor/bin/phpunit
docker compose run --rm -e REDIS_CLIENT=predis app vendor/bin/phpunit
```

The suite runs twice: once on Laravel's Redis queue, and once on Horizon's queue driver.

## License

MIT
