<?php

declare(strict_types=1);

namespace Sentinel\Drift;

use Psr\EventDispatcher\EventDispatcherInterface;
use Sentinel\Events\SchemaDriftDetected;

class DriftReporter
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher
    ) {
    }

    public function report(SchemaDrift $drift): void
    {
        $this->dispatcher->dispatch(new SchemaDriftDetected($drift));
    }
}
