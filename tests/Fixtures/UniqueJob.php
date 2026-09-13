<?php

namespace BoringO11y\UniqueJobMiddleware\Tests\Fixtures;

use BoringO11y\UniqueJobMiddleware\Middleware\EnsureUniqueLock;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A unique job that checks its lock again when it is picked up.
 */
class UniqueJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    /**
     * The number of times handle() has run.
     *
     * @var int
     */
    public static $handled = 0;

    /**
     * Whether the first attempt releases the job instead of handling it.
     *
     * @var bool
     */
    public static $releaseFirstAttempt = false;

    public $tries = 3;

    public function __construct(public $uniqueId = 'unique-1')
    {
    }

    public function middleware()
    {
        return [new EnsureUniqueLock];
    }

    public function handle()
    {
        if (static::$releaseFirstAttempt && $this->attempts() === 1) {
            $this->release();

            return;
        }

        static::$handled++;
    }
}
