<?php

declare(strict_types=1);

namespace Sentinel\Drift\Changes;

use Sentinel\Drift\Severity;

readonly class FieldAdded implements Change
{
    public function __construct(
        public string $path,
        public string $type
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getSeverity(): Severity
    {
        return Severity::ADDITIVE;
    }

    public function getDescription(): string
    {
        return "New field added ({$this->type})";
    }
}
