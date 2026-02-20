<?php

declare(strict_types=1);

namespace Sentinel\Events;

use Sentinel\Schema\StoredSchema;

readonly class SchemaHardened
{
    public function __construct(public string $endpointKey, public StoredSchema $schema)
    {
    }
}
