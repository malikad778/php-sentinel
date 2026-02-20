<?php

declare(strict_types=1);

namespace Sentinel\Store;

use Sentinel\Sampling\SampleCollection;
use Sentinel\Schema\SchemaStoreInterface;
use Sentinel\Schema\StoredSchema;

class RedisSchemaStore implements SchemaStoreInterface
{
    // Minimal mock for architecture requirement since we don't depend on ext-redis natively in core
    // In actual implementation, we'd inject \Redis or \Predis\Client
    public function __construct(private readonly mixed $redisClient)
    {
    }

    public function has(string $key): bool
    {
        return (bool) clone $this->redisClient->exists($this->getSchemaKey($key));
    }

    public function get(string $key): ?StoredSchema
    {
        $data = $this->redisClient->get($this->getSchemaKey($key));
        if (!$data) {
            return null;
        }

        $decoded = json_decode((string) $data, true);
        return StoredSchema::fromArray($decoded);
    }

    public function put(string $key, StoredSchema $schema): void
    {
        $this->redisClient->set($this->getSchemaKey($key), json_encode($schema->toArray()));
    }

    public function getSamples(string $key): SampleCollection
    {
        $elements = $this->redisClient->lrange($this->getSamplesKey($key), 0, -1);
        if (!$elements) {
            return new SampleCollection();
        }

        $samples = array_map(fn($json) => json_decode((string) $json, true), $elements);
        return new SampleCollection($samples);
    }

    public function addSample(string $key, array $payload): void
    {
        $this->redisClient->rpush($this->getSamplesKey($key), json_encode($payload));
    }

    public function archive(string $key, StoredSchema $schema): void
    {
        $archiveKey = 'sentinel:archive:' . md5($key) . ':' . $schema->version;
        $this->redisClient->set($archiveKey, json_encode($schema->toArray()));
        
        $this->redisClient->del($this->getSchemaKey($key));
        $this->redisClient->del($this->getSamplesKey($key));
    }

    public function all(): array
    {
        $keys = $this->redisClient->keys('sentinel:schema:*');
        
        // Strip prefix
        $endpointKeys = [];
        foreach ($keys as $k) {
            $endpointKeys[] = str_replace('sentinel:schema:', '', (string) $k);
        }
        
        return $endpointKeys;
    }

    private function getSchemaKey(string $key): string
    {
        return 'sentinel:schema:' . $key;
    }

    private function getSamplesKey(string $key): string
    {
        return 'sentinel:samples:' . $key;
    }
}
