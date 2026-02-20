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

        $allSchemas = array_map(
            fn(array $payload) => $this->inferenceEngine->infer($payload),
            $samples
        );

        $mergedSchema = $this->mergeAllSchemas($allSchemas, $totalCount);

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

        $this->store->clearSamples($key);
    }

    /**
     * Merges N schemas into one, tracking field presence and ALL observed types.
     * Fields present in < additiveThreshold% of samples are marked optional.
     * Fields observed as multiple types (e.g. 'null' and 'object') are represented
     * by their most common non-null type, with nullable flagged in the schema.
     *
     * @param array<int, array<string,mixed>> $schemas
     * @return array<string,mixed>
     */
    private function mergeAllSchemas(array $schemas, int $total): array
    {
        if (empty($schemas)) {
            return ['type' => 'object', 'properties' => []];
        }

        // presence[path]              = how many schemas this path appeared in
        // typeCounts[path][type]      = how many schemas had this type for this path
        // formats[path]               = last observed format hint
        // enums[path]                 = all observed string values for enum detection
        // allTemplates[path]          = the first schema node seen for this path (structure template)
        $presence     = [];
        $typeCounts   = [];
        $formats      = [];
        $enums        = [];
        $allTemplates = [];

        foreach ($schemas as $schema) {
            $this->walkAndCount($schema, '', $presence, $typeCounts, $formats, $enums, $allTemplates);
        }

        return $this->buildMergedSchema(
            $schemas[0],
            '',
            $presence,
            $typeCounts,
            $formats,
            $enums,
            $allTemplates,
            $total,
            $this->additiveThreshold
        );
    }

    /**
     * @param array<string,mixed>                 $schema
     * @param array<string,int>                   $presence
     * @param array<string,array<string,int>>     $typeCounts
     * @param array<string,string>                $formats
     * @param array<string,array<int,string>>     $enums
     * @param array<string,array<string,mixed>>   $allTemplates
     */
    private function walkAndCount(
        array $schema,
        string $path,
        array &$presence,
        array &$typeCounts,
        array &$formats,
        array &$enums,
        array &$allTemplates
    ): void {
        $type = (string) ($schema['type'] ?? 'unknown');

        // Track presence
        $presence[$path] = ($presence[$path] ?? 0) + 1;

        // Track ALL types seen at this path — not just the last one
        if (!isset($typeCounts[$path])) {
            $typeCounts[$path] = [];
        }
        $typeCounts[$path][$type] = ($typeCounts[$path][$type] ?? 0) + 1;

        // Keep the first non-null template we see for structural recursion
        if (!isset($allTemplates[$path]) && $type !== 'null') {
            $allTemplates[$path] = $schema;
        }

        if (isset($schema['format'])) {
            $formats[$path] = (string) $schema['format'];
        }

        if ($type === 'object' && isset($schema['properties'])) {
            foreach ($schema['properties'] as $key => $propSchema) {
                $childPath = $path === '' ? (string) $key : $path . '.' . $key;
                if (is_array($propSchema)) {
                    $this->walkAndCount($propSchema, $childPath, $presence, $typeCounts, $formats, $enums, $allTemplates);
                }
            }
        } elseif ($type === 'array' && isset($schema['items'])) {
            if (is_array($schema['items'])) {
                $this->walkAndCount($schema['items'], $path . '[]', $presence, $typeCounts, $formats, $enums, $allTemplates);
            }
        } elseif ($type === 'string' && isset($schema['enum']) && is_array($schema['enum'])) {
            if (!isset($enums[$path])) {
                $enums[$path] = [];
            }
            $enums[$path] = array_values(array_unique(array_map('strval', array_merge($enums[$path], $schema['enum']))));
        }
    }

    /**
     * Resolve the dominant type for a path from observed type counts.
     * If a field is sometimes null and sometimes a real type, the real type wins
     * and the field is flagged as nullable. If it's always null, type is 'null'.
     *
     * @param array<string,int> $counts  [type => count]
     * @return array{type: string, nullable: bool}
     */
    private function resolveType(array $counts): array
    {
        if (empty($counts)) {
            return ['type' => 'unknown', 'nullable' => false];
        }

        $nullCount = $counts['null'] ?? 0;
        $realCounts = array_filter($counts, fn($t) => $t !== 'null', ARRAY_FILTER_USE_KEY);

        if (empty($realCounts)) {
            // Only ever seen as null
            return ['type' => 'null', 'nullable' => false];
        }

        // Pick the most frequent non-null type
        arsort($realCounts);
        $dominantType = (string) array_key_first($realCounts);
        $isNullable   = $nullCount > 0;

        return ['type' => $dominantType, 'nullable' => $isNullable];
    }

    /**
     * @param array<string,mixed>                 $template
     * @param array<string,int>                   $presence
     * @param array<string,array<string,int>>     $typeCounts
     * @param array<string,string>                $formats
     * @param array<string,array<int,string>>     $enums
     * @param array<string,array<string,mixed>>   $allTemplates
     * @return array<string,mixed>
     */
    private function buildMergedSchema(
        array $template,
        string $path,
        array $presence,
        array $typeCounts,
        array $formats,
        array $enums,
        array $allTemplates,
        int $total,
        float $threshold
    ): array {
        $resolved = $this->resolveType($typeCounts[$path] ?? []);
        $type     = $resolved['type'];
        $nullable = $resolved['nullable'];

        $schema = ['type' => $type];

        if ($nullable) {
            $schema['nullable'] = true;
        }

        if (isset($formats[$path])) {
            $schema['format'] = $formats[$path];
        }

        if ($type === 'string' && isset($enums[$path])) {
            $enumCandidates = EnumCandidateDetector::detect($enums[$path], $total);
            if ($enumCandidates !== null) {
                $schema['enum'] = $enumCandidates;
            }
        }

        if ($type === 'object') {
            // Use the best structural template available for this path
            $structTemplate = $allTemplates[$path] ?? $template;
            $templateProps  = $structTemplate['properties'] ?? $template['properties'] ?? [];

            $schema['properties'] = [];
            $schema['required']   = [];

            foreach ($templateProps as $key => $propSchema) {
                $childPath = $path === '' ? (string) $key : $path . '.' . $key;
                if (!isset($presence[$childPath])) {
                    continue;
                }

                // Use saved template for child if available (handles fields absent from schemas[0])
                $childTemplate = $allTemplates[$childPath] ?? (is_array($propSchema) ? $propSchema : []);

                $schema['properties'][$key] = $this->buildMergedSchema(
                    $childTemplate,
                    $childPath,
                    $presence,
                    $typeCounts,
                    $formats,
                    $enums,
                    $allTemplates,
                    $total,
                    $threshold
                );

                $freq = $presence[$childPath] / $total;
                if ($freq >= $threshold) {
                    $schema['required'][] = (string) $key;
                }
            }

            if (empty($schema['required'])) {
                unset($schema['required']);
            }
        } elseif ($type === 'array') {
            $structTemplate = $allTemplates[$path] ?? $template;
            $items = $structTemplate['items'] ?? $template['items'] ?? null;
            if (is_array($items)) {
                $schema['items'] = $this->buildMergedSchema(
                    $items,
                    $path . '[]',
                    $presence,
                    $typeCounts,
                    $formats,
                    $enums,
                    $allTemplates,
                    $total,
                    $threshold
                );
            }
        }

        return $schema;
    }
}
