<?php

namespace BoringO11y\UniqueJobMiddleware\Tests;

use BoringO11y\UniqueJobMiddleware\LockRecord;
use BoringO11y\UniqueJobMiddleware\UniqueJobMiddlewareServiceProvider;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection()->flushdb();
    }

    protected function tearDown(): void
    {
        Redis::connection()->flushdb();

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [UniqueJobMiddlewareServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $redis = [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'database' => 5,
        ];

        $app['config']->set('database.redis.client', env('REDIS_CLIENT', 'phpredis'));
        $app['config']->set('database.redis.default', $redis);
        $app['config']->set('database.redis.cache', $redis);

        // Locks live in Redis, as they would across real worker processes.
        $app['config']->set('cache.default', 'redis');

        $app['config']->set('queue.default', 'redis');
        $app['config']->set('queue.connections.redis', [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
    }

    /**
     * Run the next job on the default queue.
     */
    protected function work(int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->app->make('queue.worker')->runNextJob('redis', 'default', tap(new WorkerOptions, function ($options) {
                $options->sleep = 0;
                $options->maxTries = 1;
            }));
        }
    }

    /**
     * Get the decoded payload at the given position on the default queue.
     */
    protected function queuedPayload(int $index = 0): ?array
    {
        $payload = Redis::connection()->lindex($this->queueKey(), $index);

        return is_string($payload) ? json_decode($payload, true) : null;
    }

    /**
     * Get the Redis key of the default queue.
     */
    protected function queueKey(): string
    {
        $queue = Queue::connection('redis');

        return method_exists($queue, 'queueRedisKey') ? $queue->queueRedisKey('default') : $queue->getQueue('default');
    }

    /**
     * Take the job's lock as another job would.
     */
    protected function holdLock($job, string $owner = 'other'): void
    {
        $this->assertTrue($this->app->make(Cache::class)->getStore()->lock($this->lockKey($job), 0, $owner)->get());
    }

    /**
     * Release the job's lock regardless of its owner.
     */
    protected function releaseLock($job): void
    {
        $this->app->make(Cache::class)->getStore()->lock($this->lockKey($job))->forceRelease();
    }

    /**
     * Get the owner the job's lock is currently held by.
     */
    protected function lockOwner($job): ?string
    {
        return LockRecord::currentOwner($this->app->make(Cache::class), $this->lockKey($job));
    }

    protected function lockKey($job): string
    {
        return LockRecord::key($job);
    }

    protected function setUpBatchTable(): void
    {
        $this->app['config']->set('queue.batching.database', 'testing');
        $this->app['config']->set('queue.batching.table', 'job_batches');
        $this->app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        Schema::connection('testing')->create('job_batches', static function ($table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }
}
