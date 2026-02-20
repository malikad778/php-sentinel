<?php

declare(strict_types=1);

namespace Sentinel\Drift\Changes;

use Sentinel\Drift\Severity;

readonly class RequiredNowOptional implements Change
{
    public function __construct(
        public string $path,
        public float $probability = 0.0
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getSeverity(): Severity
    {
        return Severity::BREAKING;
    }

    public function getDescription(): string
    {
        return "Previously required field is now sometimes absent";
    }
}
