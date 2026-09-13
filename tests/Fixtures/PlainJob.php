<?php

namespace BoringO11y\UniqueJobMiddleware\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class PlainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public static $handled = 0;

    public function handle()
    {
        static::$handled++;
    }
}
