<?php

declare(strict_types=1);

namespace Sentinel\Drift;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Sentinel\Events\SchemaDriftDetected;

class DriftReporter
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function report(SchemaDrift $drift): void
    {
        if ($this->logger) {
            $context = [
                'endpoint' => $drift->endpoint,
                'severity' => $drift->severity->value,
                'changes'  => count($drift->changes),
            ];
            
            if ($drift->severity === Severity::BREAKING) {
                $this->logger->error("Sentinel API Schema Drift: BREAKING change detected on {$drift->endpoint}", $context);
            } elseif ($drift->severity === Severity::ADDITIVE) {
                $this->logger->info("Sentinel API Schema Drift: Additive change on {$drift->endpoint}", $context);
            } else {
                $this->logger->warning("Sentinel API Schema Drift: Minor change on {$drift->endpoint}", $context);
            }
        }

        $this->dispatcher->dispatch(new SchemaDriftDetected($drift));
    }
}
