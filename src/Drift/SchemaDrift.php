<?php

declare(strict_types=1);

namespace Sentinel\Drift;

use Sentinel\Drift\Changes\Change;

readonly class SchemaDrift
{
    /**
     * @param string $endpoint The endpoint key that drifted
     * @param \DateTimeImmutable $detectedAt When the drift was detected
     * @param Severity $severity The highest severity among changes
     * @param array<int, Change> $changes The list of specific structural changes
     * @param string $previousSchemaVersion The hash of the schema that was drifted from
     * @param string $newSchemaVersion The hash of the newly inferred schema
     */
    public function __construct(
        public string $endpoint,
        public \DateTimeImmutable $detectedAt,
        public Severity $severity,
        public array $changes,
        public string $previousSchemaVersion,
        public string $newSchemaVersion
    ) {
    }

    /**
     * Helper to classify the highest severity
     *
     * @param array<int, Change> $changes
     */
    public static function resolveHighestSeverity(array $changes): Severity
    {
        $highest = Severity::ADVISORY;

        foreach ($changes as $change) {
            if ($change->getSeverity() === Severity::BREAKING) {
                return Severity::BREAKING; // Can't go higher
            }
            if ($change->getSeverity() === Severity::ADDITIVE) {
                $highest = Severity::ADDITIVE;
            }
        }

        return $highest;
    }
}
