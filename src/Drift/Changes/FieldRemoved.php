<?php

declare(strict_types=1);

namespace Sentinel\Drift\Changes;

use Sentinel\Drift\Severity;

readonly class FieldRemoved implements Change
{
    public function __construct(
        public string $path,
        public string $previousType
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
        return "Field removed (was {$this->previousType})";
    }
}
