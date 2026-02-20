<?php

declare(strict_types=1);

namespace Sentinel\Inference;

use Sentinel\Sampling\SampleCollection;

class InferenceEngine
{
    /**
     * Infers a basic JSON Schema from a single JSON-decoded array payload.
     * Use this for single response structural profile.
     *
     * @param array<mixed> $payload
     * @return array<string, mixed>
     */
    public function infer(array $payload): array
    {
        return $this->walk($payload);
    }

    /**
     * Walks the data structure recursively to build the schema node.
     */
    /**
     * @return array<string, mixed>
     */
    private function walk(mixed $value): array
    {
        $type = TypeResolver::resolve($value);

        if ($type === 'null') {
            return ['type' => 'null'];
        }

        if ($type === 'object') {
            $properties = [];
            foreach ($value as $k => $v) {
                $properties[$k] = $this->walk($v);
            }
            return [
                'type' => 'object',
                'properties' => $properties,
                'required' => array_keys($properties),
            ];
        }

        if ($type === 'array') {
            if ($value === []) {
                return ['type' => 'array', 'items' => []];
            }

            // Attempt to infer item type by merging item schemas
            $itemSchemas = [];
            foreach ($value as $item) {
                // To do this deeply for single items we just collect formats
                $itemSchemas[] = $this->walk($item);
            }
            return [
                'type' => 'array',
                'items' => $this->mergeSchemas($itemSchemas),
            ];
        }

        // Primitive scalar types
        $schema = ['type' => $type];

        if ($type === 'string') {
            $format = FormatHintDetector::detect((string) $value);
            if ($format !== null) {
                $schema['format'] = $format;
            }
            $schema['enum'] = [(string) $value];
        }

        return $schema;
    }

    /**
     * Naive merge of schemas for a heterogeneous array in a single sample.
     * Typically, proper hardening checks across all samples instead.
     *
     * @param array<array<string, mixed>> $schemas
     * @return array<string, mixed>
     */
    private function mergeSchemas(array $schemas): array
    {
        $uniqueTypes = [];
        $merged = [];
        foreach ($schemas as $schema) {
            $type = $schema['type'] ?? 'unknown';
            if (!isset($uniqueTypes[$type])) {
                $uniqueTypes[$type] = $schema;
                $merged[] = $schema;
            } else {
                // If it's an object, we should ideally deeply merge properties
                // This is a naive implementation for a single array payload
            }
        }

        if (count($merged) === 1) {
            return $merged[0];
        }

        if (count($merged) === 0) {
            return [];
        }

        return ['oneOf' => $merged];
    }
}
