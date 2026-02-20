<?php

declare(strict_types=1);

namespace Sentinel\Drift\Changes;

use Sentinel\Drift\Severity;

readonly class FormatChanged implements Change
{
    public function __construct(
        public string $path,
        public string $from,
        public string $to
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getSeverity(): Severity
    {
        return Severity::ADVISORY;
    }

    public function getDescription(): string
    {
        return "String format changed ({$this->from} -> {$this->to})";
    }
}
