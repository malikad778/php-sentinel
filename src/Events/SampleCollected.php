<?php

declare(strict_types=1);

namespace Sentinel\Events;

readonly class SampleCollected
{
    /**
     * @param array<mixed> $payload
     */
    public function __construct(public string $endpointKey, public array $payload)
    {
    }
}
