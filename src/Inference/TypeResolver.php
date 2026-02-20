<?php

declare(strict_types=1);

namespace Sentinel\Inference;

class TypeResolver
{
    /**
     * Given a raw PHP value decoded from JSON, returns the base JSON Schema Type.
     */
    public static function resolve(mixed $value): string
    {
        return match (true) {
            is_string($value) => 'string',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_bool($value) => 'boolean',
            is_null($value) => 'null',
            is_array($value) => self::resolveArrayType($value),
            default => 'string', // Fallback for edge cases
        };
    }

    /**
     * Determines if an array should be treated as an object or list.
     * @param array<mixed> $value
     */
    private static function resolveArrayType(array $value): string
    {
        if ($value === []) {
            return 'array';
        }

        // If it's a list (keys are sequential 0, 1, 2...)
        if (array_is_list($value)) {
            return 'array';
        }

        // Associative arrays are objects in JSON schema
        return 'object';
    }
}
