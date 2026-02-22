<?php

declare(strict_types=1);

namespace Sentinel\Inference;

class EnumCandidateDetector
{
    private const MAX_DISTINCT_VALUES = 8;
    private const MIN_SAMPLES_REQUIRED = 30;

    /**
     * Checks if a string field is a candidate to become an enum.
     *
     * @param array<int, string|null> $observedValues Across all samples for this field
     * @param int $totalSamples Total number of samples processed for the endpoint
     * @return array<int, string>|null The distinct enum values, or null if not a candidate
     */
    public static function detect(array $observedValues, int $totalSamples): ?array
    {
        if ($totalSamples < self::MIN_SAMPLES_REQUIRED) {
            return null;
        }

        // Filter out nulls
        $stringValues = array_filter($observedValues, fn($v) => is_string($v));
        
        $distinct = array_values(array_unique($stringValues));

        if (count($distinct) > 0 && count($distinct) <= self::MAX_DISTINCT_VALUES) {
            sort($distinct);
            return $distinct;
        }

        return null;
    }
}
