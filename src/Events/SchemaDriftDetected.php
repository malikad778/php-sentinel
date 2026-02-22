<?php

declare(strict_types=1);

namespace Sentinel\Events;

use Sentinel\Drift\SchemaDrift;

readonly class SchemaDriftDetected
{
    public function __construct(public SchemaDrift $drift)
    {
    }
}
