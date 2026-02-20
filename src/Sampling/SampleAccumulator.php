<?php

declare(strict_types=1);

namespace Sentinel\Sampling;

use Sentinel\Inference\InferenceEngine;
use Sentinel\Schema\SchemaStoreInterface;
use Sentinel\Schema\StoredSchema;

class SampleAccumulator
{
    public function __construct(
        private readonly SchemaStoreInterface $store,
        private readonly InferenceEngine $inferenceEngine,
        private readonly int $sampleThreshold = 20
    ) {
    }

    /**
     * Processes a single payload JSON array.
     * Returns true if a hardening event occurred during this sample.
     *
     * @param array<mixed> $payload
     */
    public function accumulate(string $endpointKey, array $payload): bool
    {
        // Add sample to the store
        $this->store->addSample($endpointKey, $payload);
        $collection = $this->store->getSamples($endpointKey);
        
        $currentCount = $collection->count();
        
        if ($currentCount === $this->sampleThreshold) {
            $this->harden($endpointKey, $collection);
            return true;
        }

        return false;
    }

    /**
     * Takes a sample collection and produces a final validated inference schema.
     */
    private function harden(string $key, SampleCollection $collection): void
    {
        // In a true multi-sample inference, we would loop through $collection->all()
        // and compute missing optionalities + type permutations.
        // For simplicity, we'll infer heavily off the last payload since we don't have a 
        // robust Deep Merge Schema implementation yet in the specification scope.
        $samples = $collection->all();
        $basePayload = end($samples);
        if (!is_array($basePayload)) {
            $basePayload = [];
        }
        $baseSchema = $this->inferenceEngine->infer($basePayload);
        
        // Version hash generated from the compiled schema
        $version = 'sha256:' . hash('sha256', json_encode($baseSchema) ?: '');

        $stored = new StoredSchema(
            version: $version,
            jsonSchema: $baseSchema,
            sampleCount: count($samples),
            hardenedAt: new \DateTimeImmutable()
        );

        $this->store->put($key, $stored);
    }
}
