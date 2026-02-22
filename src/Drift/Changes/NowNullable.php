<?php

declare(strict_types=1);

namespace Sentinel\Drift\Changes;

use Sentinel\Drift\Severity;

readonly class NowNullable implements Change
{
    public function __construct(
        public string $path
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
        return "Previously non-null field now returns null";
    }
}
