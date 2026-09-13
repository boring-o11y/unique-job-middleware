<?php

namespace BoringO11y\UniqueJobMiddleware\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

class UniqueUntilProcessingJob extends UniqueJob implements ShouldBeUniqueUntilProcessing
{
}
