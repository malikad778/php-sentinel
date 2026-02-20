<?php

declare(strict_types=1);

namespace Sentinel\Drift;

enum Severity: string
{
    case BREAKING = 'BREAKING';
    case ADDITIVE = 'ADDITIVE';
    case ADVISORY = 'ADVISORY';
}
