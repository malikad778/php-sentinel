<?php

declare(strict_types=1);

namespace Sentinel\Sampling;

use Psr\EventDispatcher\EventDispatcherInterface;
use Sentinel\Events\SampleCollected;
use Sentinel\Events\SchemaHardened;
use Sentinel\Inference\EnumCandidateDetector;
use Sentinel\Inference\InferenceEngine;
use Sentinel\Schema\SchemaStoreInterface;
use Sentinel\Schema\StoredSchema;

class SampleAccumulator
{
    public function __construct(
        private readonly SchemaStoreInterface $store,
        private readonly InferenceEngine $inferenceEngine,
        private readonly int $sampleThreshold = 20,
        private readonly float $additiveThreshold = 0.95,
        private readonly ?EventDispatcherInterface $dispatcher = null
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
        
        if ($this->dispatcher) {
            $this->dispatcher->dispatch(new SampleCollected($endpointKey, $payload));
        }

        $collection = $this->store->getSamples($endpointKey);
        
        if ($collection->count() >= $this->sampleThreshold) {
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
        $samples = $collection->all();
        $totalCount = count($samples);

        // Infer schema from every sample independently
        $allSchemas = array_map(
            fn(array $payload) => $this->inferenceEngine->infer($payload),
            $samples
        );

        // Merge all schemas
        $mergedSchema = $this->mergeAllSchemas($allSchemas, $totalCount);

        // Version hash generated from the compiled schema
        $version = 'sha256:' . hash('sha256', json_encode($mergedSchema) ?: '');

        $stored = new StoredSchema(
            version: $version,
            jsonSchema: $mergedSchema,
            sampleCount: $totalCount,
            hardenedAt: new \DateTimeImmutable()
        );

        $this->store->put($key, $stored);
        
        if ($this->dispatcher) {
            $this->dispatcher->dispatch(new SchemaHardened($key, $stored));
        }

        // Clean up samples after a harden event to save space.
        $this->store->clearSamples($key);
    }

    /**
     * Merges N schemas into one, tracking field presence frequency.
     * Fields present in < additiveThreshold% of samples are marked optional.
     *
     * @param array<int, array<string,mixed>> $schemas
     * @return array<string,mixed>
     */
    private function mergeAllSchemas(array $schemas, int $total): array
    {
        $fieldPresence = [];
        $fieldTypes    = [];
        $fieldFormats  = [];
        $fieldEnums    = [];

        foreach ($schemas as $schema) {
            $this->walkAndCount($schema, '', $fieldPresence, $fieldTypes, $fieldFormats, $fieldEnums);
        }

        // If no schemas were provided, return an empty object schema
        if (empty($schemas)) {
            return ['type' => 'object', 'properties' => []];
        }

        return $this->buildMergedSchema(
            $schemas[0], // Use the first schema as a template for structure
            '',
            $fieldPresence,
            $fieldTypes,
            $fieldFormats,
            $fieldEnums,
            $total,
            $this->additiveThreshold
        );
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,int> $presence
     * @param array<string,string> $types
     * @param array<string,string> $formats
     * @param array<string,array<int,string>> $enums
     */
    private function walkAndCount(array $schema, string $path, array &$presence, array &$types, array &$formats, array &$enums): void
    {
        $type = $schema['type'] ?? 'unknown';
        
        if (!isset($presence[$path])) {
            $presence[$path] = 0;
            $types[$path] = (string) $type;
        }
        $presence[$path]++;

        if (isset($schema['format'])) {
            $formats[$path] = (string) $schema['format'];
        }

        if ($type === 'object' && isset($schema['properties'])) {
            foreach ($schema['properties'] as $key => $propSchema) {
                $childPath = $path === '' ? (string) $key : $path . '.' . $key;
                if (is_array($propSchema)) {
                    $this->walkAndCount($propSchema, $childPath, $presence, $types, $formats, $enums);
                }
            }
        } elseif ($type === 'array' && isset($schema['items'])) {
            if (is_array($schema['items'])) {
                $this->walkAndCount($schema['items'], $path . '[]', $presence, $types, $formats, $enums);
            }
        } elseif ($type === 'string' && isset($schema['enum']) && is_array($schema['enum'])) {
            if (!isset($enums[$path])) {
                $enums[$path] = [];
            }
            $enums[$path] = array_values(array_unique(array_map('strval', array_merge($enums[$path], $schema['enum']))));
        }
    }

    /**
     * @param array<string,mixed> $template
     * @param array<string,int> $presence
     * @param array<string,string> $types
     * @param array<string,string> $formats
     * @param array<string,array<int,string>> $enums
     * @return array<string,mixed>
     */
    private function buildMergedSchema(array $template, string $path, array $presence, array $types, array $formats, array $enums, int $total, float $threshold): array
    {
        $type = $types[$path] ?? ($template['type'] ?? 'unknown');
        $schema = ['type' => $type];

        if (isset($formats[$path])) {
            $schema['format'] = $formats[$path];
        }

        if ($type === 'string' && isset($enums[$path])) {
            $enumCandidates = EnumCandidateDetector::detect($enums[$path], $total);
            if ($enumCandidates !== null) {
                $schema['enum'] = $enumCandidates;
            }
        }

        if ($type === 'object' && isset($template['properties'])) {
            $schema['properties'] = [];
            $schema['required'] = [];

            foreach ($template['properties'] as $key => $propSchema) {
                $childPath = $path === '' ? (string) $key : $path . '.' . $key;
                if (!isset($presence[$childPath])) {
                    continue;
                }

                $schema['properties'][$key] = $this->buildMergedSchema(
                    is_array($propSchema) ? $propSchema : [],
                    $childPath,
                    $presence,
                    $types,
                    $formats,
                    $enums,
                    $total,
                    $threshold
                );

                $freq = $presence[$childPath] / $total;
                if ($freq >= $threshold) {
                    $schema['required'][] = (string) $key;
                }
            }
            // Ensure 'required' array is only present if it has elements
            if (empty($schema['required'])) {
                unset($schema['required']);
            }
        } elseif ($type === 'array' && isset($template['items']) && is_array($template['items'])) {
            $schema['items'] = $this->buildMergedSchema(
                $template['items'],
                $path . '[]',
                $presence,
                $types,
                $formats,
                $enums,
                $total,
                $threshold
            );
        }

        return $schema;
    }
}
