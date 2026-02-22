<?php

declare(strict_types=1);

namespace Sentinel\Store;

use Sentinel\Sampling\SampleCollection;
use Sentinel\Schema\SchemaStoreInterface;
use Sentinel\Schema\StoredSchema;

class ArraySchemaStore implements SchemaStoreInterface
{
    /** @var array<string, StoredSchema> */
    private array $schemas = [];

    /** @var array<string, array<int, array<mixed>>> */
    private array $samples = [];

    /** @var array<string, array<int, StoredSchema>> */
    private array $archives = [];

    public function has(string $key): bool
    {
        return isset($this->schemas[$key]);
    }

    public function get(string $key): ?StoredSchema
    {
        return $this->schemas[$key] ?? null;
    }

    public function put(string $key, StoredSchema $schema): void
    {
        $this->schemas[$key] = $schema;
    }

    public function getSamples(string $key): SampleCollection
    {
        return new SampleCollection($this->samples[$key] ?? []);
    }

    public function addSample(string $key, array $payload): void
    {
        if (!isset($this->samples[$key])) {
            $this->samples[$key] = [];
        }
        $this->samples[$key][] = $payload;
    }

    public function clearSamples(string $key): void
    {
        unset($this->samples[$key]);
    }

    public function archive(string $key, StoredSchema $schema): void
    {
        if (!isset($this->archives[$key])) {
            $this->archives[$key] = [];
        }
        $this->archives[$key][] = $schema;
        unset($this->schemas[$key]);
        unset($this->samples[$key]);
    }

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        return array_keys($this->schemas);
    }

    /**
     * Extra helper for tests
     *
     * @return array<int, StoredSchema>
     */
    public function getArchives(string $key): array
    {
        return $this->archives[$key] ?? [];
    }

    public function reset(): void
    {
        $this->schemas  = [];
        $this->samples  = [];
        $this->archives = [];
    }
}
