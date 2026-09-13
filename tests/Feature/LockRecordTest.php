<?php

namespace BoringO11y\UniqueJobMiddleware\Tests\Feature;

use BoringO11y\UniqueJobMiddleware\LockRecord;
use BoringO11y\UniqueJobMiddleware\Tests\Fixtures\PlainJob;
use BoringO11y\UniqueJobMiddleware\Tests\Fixtures\UniqueJob;
use BoringO11y\UniqueJobMiddleware\Tests\Fixtures\UniqueListener;
use BoringO11y\UniqueJobMiddleware\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

class LockRecordTest extends TestCase
{
    public function test_a_dispatch_records_the_owner_of_the_lock_it_took()
    {
        dispatch(new UniqueJob);

        $record = $this->queuedPayload()[LockRecord::PAYLOAD_KEY];

        $this->assertSame($this->lockKey(new UniqueJob), $record['key']);
        $this->assertIsString($record['owner']);
        $this->assertSame($record['owner'], $this->lockOwner(new UniqueJob));
    }

    public function test_a_direct_push_records_no_owner()
    {
        Queue::push(new UniqueJob);

        $this->assertSame(
            ['key' => $this->lockKey(new UniqueJob), 'owner' => null],
            $this->queuedPayload()[LockRecord::PAYLOAD_KEY]
        );
    }

    public function test_a_push_made_while_another_job_holds_the_lock_records_no_owner()
    {
        $this->holdLock(new UniqueJob);

        Queue::push(new UniqueJob);

        $this->assertNull($this->queuedPayload()[LockRecord::PAYLOAD_KEY]['owner']);
    }

    public function test_the_first_job_of_a_chain_records_no_owner()
    {
        Bus::chain([new UniqueJob, new PlainJob])->dispatch();

        $this->assertNull($this->queuedPayload()[LockRecord::PAYLOAD_KEY]['owner']);
    }

    public function test_a_batched_job_records_no_owner()
    {
        $this->setUpBatchTable();

        Bus::batch([new UniqueJob])->dispatch();

        $this->assertNull($this->queuedPayload()[LockRecord::PAYLOAD_KEY]['owner']);
    }

    public function test_a_queued_listener_records_the_owner_of_the_lock_it_took()
    {
        if (! method_exists(CallQueuedListener::class, 'shouldBeUnique')) {
            $this->markTestSkipped('Queued listeners cannot be unique on this Laravel version.');
        }

        Event::listen('unique-listener-test', UniqueListener::class);

        Event::dispatch('unique-listener-test', [['id' => 1]]);

        $record = $this->queuedPayload()[LockRecord::PAYLOAD_KEY];

        $this->assertIsString($record['owner']);
        $this->assertSame($record['owner'], LockRecord::currentOwner($this->app->make(Cache::class), $record['key']));
    }

    public function test_a_scheduled_job_records_the_owner_of_the_lock_it_took()
    {
        if (! method_exists(Schedule::class, 'dispatchUniqueJobToQueue')) {
            $this->markTestSkipped('The scheduler does not lock unique jobs on this Laravel version.');
        }

        $this->app->make(Schedule::class)->job(new UniqueJob)->run($this->app);

        $record = $this->queuedPayload()[LockRecord::PAYLOAD_KEY];

        $this->assertIsString($record['owner']);
        $this->assertSame($record['owner'], $this->lockOwner(new UniqueJob));
    }

    public function test_a_job_that_is_not_unique_records_nothing()
    {
        Queue::push(new PlainJob);

        $this->assertArrayNotHasKey(LockRecord::PAYLOAD_KEY, $this->queuedPayload());
    }
}
