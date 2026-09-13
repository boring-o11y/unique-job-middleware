<?php

namespace BoringO11y\UniqueJobMiddleware\Tests\Feature;

use BoringO11y\UniqueJobMiddleware\Events\UniqueJobSkipped;
use BoringO11y\UniqueJobMiddleware\LockRecord;
use BoringO11y\UniqueJobMiddleware\Tests\Fixtures\PlainJob;
use BoringO11y\UniqueJobMiddleware\Tests\Fixtures\UniqueJob;
use BoringO11y\UniqueJobMiddleware\Tests\Fixtures\UniqueUntilProcessingJob;
use BoringO11y\UniqueJobMiddleware\Tests\TestCase;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use ReflectionMethod;

class EnsureUniqueLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        UniqueJob::$handled = 0;
        UniqueJob::$releaseFirstAttempt = false;
        PlainJob::$handled = 0;
    }

    public function test_a_job_holding_its_lock_runs()
    {
        dispatch(new UniqueJob);

        $this->work();

        $this->assertSame(1, UniqueJob::$handled);
        $this->assertNull($this->lockOwner(new UniqueJob));
    }

    public function test_a_copy_queued_while_another_job_holds_the_lock_is_skipped()
    {
        Event::fake([UniqueJobSkipped::class]);

        $this->holdLock(new UniqueJob);

        Queue::push(new UniqueJob);

        $this->work();

        $this->assertSame(0, UniqueJob::$handled);
        $this->assertSame(0, Redis::connection()->llen($this->queueKey()));
        $this->assertSame('other', $this->lockOwner(new UniqueJob), 'The other job should keep its lock.');

        Event::assertDispatched(UniqueJobSkipped::class, function ($event) {
            return $event->lockKey === $this->lockKey(new UniqueJob)
                && $event->command instanceof UniqueJob
                && $event->job->isDeleted();
        });
    }

    public function test_a_copy_runs_when_the_lock_is_free_by_the_time_it_is_picked_up()
    {
        $this->holdLock(new UniqueJob);

        Queue::push(new UniqueJob);

        $this->releaseLock(new UniqueJob);

        $this->work();

        $this->assertSame(1, UniqueJob::$handled);
        $this->assertNull($this->lockOwner(new UniqueJob));
    }

    public function test_a_copy_that_took_the_free_lock_still_holds_it_on_a_later_attempt()
    {
        UniqueJob::$releaseFirstAttempt = true;

        Queue::push(new UniqueJob);

        $this->work();

        $this->assertSame(0, UniqueJob::$handled);
        $this->assertNotNull($this->lockOwner(new UniqueJob), 'A released job keeps the lock it took.');

        $this->work();

        $this->assertSame(1, UniqueJob::$handled);
        $this->assertNull($this->lockOwner(new UniqueJob));
    }

    public function test_a_dispatched_job_whose_lock_was_taken_over_is_skipped()
    {
        dispatch(new UniqueJob);

        // Its lock lapsed and another dispatch took the key.
        $this->releaseLock(new UniqueJob);
        $this->holdLock(new UniqueJob);

        $this->work();

        $this->assertSame(0, UniqueJob::$handled);
        $this->assertSame('other', $this->lockOwner(new UniqueJob));
    }

    public function test_a_retried_payload_runs_again_once_the_lock_is_free()
    {
        dispatch(new UniqueJob);

        $payload = Redis::connection()->lindex($this->queueKey(), 0);

        $this->work();

        // As queue:retry re-queues the stored payload.
        Queue::connection('redis')->pushRaw($payload);

        $this->work();

        $this->assertSame(2, UniqueJob::$handled);
        $this->assertNull($this->lockOwner(new UniqueJob));
    }

    public function test_a_skipped_job_unique_until_processing_leaves_the_other_lock_alone()
    {
        if (! $this->frameworkReleasesUntilProcessingLocksInsideMiddleware()) {
            $this->markTestSkipped('This Laravel version releases the lock before middleware runs.');
        }

        $this->holdLock(new UniqueUntilProcessingJob);

        Queue::push(new UniqueUntilProcessingJob);

        $this->work();

        $this->assertSame(0, UniqueJob::$handled);
        $this->assertSame('other', $this->lockOwner(new UniqueUntilProcessingJob));
    }

    public function test_a_job_unique_until_processing_releases_the_free_lock_it_took()
    {
        Queue::push(new UniqueUntilProcessingJob);

        $this->work();

        $this->assertSame(1, UniqueJob::$handled);
        $this->assertNull($this->lockOwner(new UniqueUntilProcessingJob));
    }

    public function test_the_rest_of_a_skipped_chain_is_not_dispatched()
    {
        $this->holdLock(new UniqueJob);

        Bus::chain([new UniqueJob, new PlainJob])->dispatch();

        $this->work();

        $this->assertSame(0, UniqueJob::$handled);
        $this->assertSame(0, Redis::connection()->llen($this->queueKey()));
        $this->assertSame('other', $this->lockOwner(new UniqueJob));
    }

    public function test_a_skipped_batched_job_lets_the_batch_finish()
    {
        $this->setUpBatchTable();

        $this->holdLock(new UniqueJob);

        $batch = Bus::batch([new UniqueJob])->dispatch();

        $this->work();

        $this->assertSame(0, UniqueJob::$handled);
        $this->assertTrue(Bus::findBatch($batch->id)->finished());
        $this->assertSame('other', $this->lockOwner(new UniqueJob));
    }

    public function test_a_job_with_no_record_runs()
    {
        $this->holdLock(new UniqueJob);

        Queue::push(new UniqueJob);

        // As a job queued before the package was installed.
        $payload = $this->queuedPayload();
        unset($payload[LockRecord::PAYLOAD_KEY]);
        Redis::connection()->lset($this->queueKey(), 0, json_encode($payload));

        $this->work();

        $this->assertSame(1, UniqueJob::$handled);
    }

    public function test_a_job_dispatched_on_the_sync_connection_runs()
    {
        config(['queue.default' => 'sync']);

        dispatch(new UniqueJob);

        $this->assertSame(1, UniqueJob::$handled);
        $this->assertNull($this->lockOwner(new UniqueJob));
    }

    /**
     * Determine whether the framework releases a lock unique until processing
     * after the job's middleware has run, rather than before it.
     */
    protected function frameworkReleasesUntilProcessingLocksInsideMiddleware(): bool
    {
        $method = new ReflectionMethod(CallQueuedHandler::class, 'dispatchThroughMiddleware');
        $lines = array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);

        return str_contains(implode('', $lines), 'UniqueUntilProcessing');
    }
}
