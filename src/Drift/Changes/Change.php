<?php

declare(strict_types=1);

namespace Sentinel\Drift\Changes;

use Sentinel\Drift\Severity;

interface Change
{
    public function getPath(): string;
    public function getSeverity(): Severity;
    public function getDescription(): string;
}
