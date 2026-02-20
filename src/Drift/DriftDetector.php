<?php

declare(strict_types=1);

namespace Sentinel\Drift;

use Sentinel\Drift\Changes\FieldAdded;
use Sentinel\Drift\Changes\FieldRemoved;
use Sentinel\Drift\Changes\FormatChanged;
use Sentinel\Drift\Changes\TypeChanged;
use Sentinel\Schema\StoredSchema;

class DriftDetector
{
    /**
     * Compares a baseline schema array structure against a newly inferred schema array structure.
     * Returns a SchemaDrift object if changes are found, or null otherwise.
     *
     * @param string $endpointKey
     * @param StoredSchema $hardened Current stored schema
     * @param array<string, mixed> $inferred Newly inferred schema matching this sample
     * @return SchemaDrift|null
     */
    public function detect(string $endpointKey, StoredSchema $hardened, array $inferred): ?SchemaDrift
    {
        $changes = $this->diff($hardened->jsonSchema, $inferred, '');

        if (count($changes) === 0) {
            return null;
        }

        $severity = SchemaDrift::resolveHighestSeverity($changes);
        $newVersion = 'sha256:' . hash('sha256', json_encode($inferred) ?: '');

        return new SchemaDrift(
            endpoint: $endpointKey,
            detectedAt: new \DateTimeImmutable(),
            severity: $severity,
            changes: $changes,
            previousSchemaVersion: $hardened->version,
            newSchemaVersion: $newVersion
        );
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @param string $path
     * @return array<int, \Sentinel\Drift\Changes\Change>
     */
    private function diff(array $old, array $new, string $path): array
    {
        $changes = [];

        $oldType = $old['type'] ?? 'unknown';
        $newType = $new['type'] ?? 'unknown';

        if ($oldType !== $newType) {
            $changes[] = new TypeChanged($path ?: '$root', $oldType, $newType);
            // If type changed completely, structural diffing below won't make sense
            return $changes;
        }

        if ($oldType === 'object') {
            $oldProps = $old['properties'] ?? [];
            $newProps = $new['properties'] ?? [];

            foreach ($oldProps as $key => $oldPropDef) {
                $propPath = $path === '' ? $key : $path . '.' . $key;
                
                if (!isset($newProps[$key])) {
                    $changes[] = new FieldRemoved($propPath, $oldPropDef['type'] ?? 'unknown');
                    continue;
                }

                $changes = array_merge($changes, $this->diff($oldPropDef, $newProps[$key], $propPath));
            }

            foreach ($newProps as $key => $newPropDef) {
                $propPath = $path === '' ? $key : $path . '.' . $key;
                
                if (!isset($oldProps[$key])) {
                    $changes[] = new FieldAdded($propPath, $newPropDef['type'] ?? 'unknown');
                }
            }
        } elseif ($oldType === 'array') {
            $oldItems = $old['items'] ?? [];
            $newItems = $new['items'] ?? [];

            if ($oldItems !== [] && $newItems !== []) {
                $changes = array_merge($changes, $this->diff($oldItems, $newItems, $path . '[]'));
            }
        } elseif ($oldType === 'string') {
            $oldFormat = $old['format'] ?? null;
            $newFormat = $new['format'] ?? null;

            if ($oldFormat !== $newFormat && $oldFormat !== null && $newFormat !== null) {
                $changes[] = new FormatChanged($path, $oldFormat, $newFormat);
            }
        }

        // We omit deeper probabilistic checking (NowNullable, RequiredNowOptional, Enums) here for simplicity unless requested
        // as determining requiredness without the whole multi-sample sequence logic requires a complete probability matrix overlay.

        return $changes;
    }
}
