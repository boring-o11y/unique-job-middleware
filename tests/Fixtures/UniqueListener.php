<?php

namespace BoringO11y\UniqueJobMiddleware\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

class UniqueListener implements ShouldBeUnique, ShouldQueue
{
    public function uniqueId()
    {
        return 'listener-1';
    }

    public function handle($event)
    {
        //
    }
}
