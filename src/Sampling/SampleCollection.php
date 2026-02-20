<?php

declare(strict_types=1);

namespace Sentinel\Sampling;

class SampleCollection
{
    /**
     * @param array<array<mixed>> $samples
     */
    public function __construct(private array $samples = [])
    {
    }

    /**
     * @return array<array<mixed>>
     */
    public function all(): array
    {
        return $this->samples;
    }

    /**
     * @param array<mixed> $payload
     */
    public function add(array $payload): void
    {
        $this->samples[] = $payload;
    }

    public function count(): int
    {
        return count($this->samples);
    }
}
