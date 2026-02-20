<?php

declare(strict_types=1);

namespace Sentinel\Schema;

use Sentinel\Sampling\SampleCollection;

interface SchemaStoreInterface
{
    /**
     * Check if a schema exists for the given key.
     */
    public function has(string $key): bool;

    /**
     * Retrieve a stored schema by its key.
     */
    public function get(string $key): ?StoredSchema;

    /**
     * Persist a hardened schema for the given key.
     */
    public function put(string $key, StoredSchema $schema): void;

    /**
     * Retrieve all collected samples for the given key.
     */
    public function getSamples(string $key): SampleCollection;

    /**
     * Add a new single response sample payload to the store for accumulation.
     *
     * @param array<mixed> $payload
     */
    public function addSample(string $key, array $payload): void;

    /**
     * Archive the current schema for the key (usually when drift is detected).
     */
    public function archive(string $key, StoredSchema $schema): void;

    /**
     * Returns an array of all tracked endpoint keys.
     *
     * @return array<int, string>
     */
    public function all(): array;
}
