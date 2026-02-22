<?php

declare(strict_types=1);

namespace Sentinel\Drift\Changes;

use Sentinel\Drift\Severity;

readonly class EnumValueRemoved implements Change
{
    public function __construct(
        public string $path,
        public string $value
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
        return "Previously observed enum value '{$this->value}' no longer appears";
    }
}
