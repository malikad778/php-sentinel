<?php

declare(strict_types=1);

namespace Sentinel\Drift;

use Sentinel\Drift\Changes\FieldAdded;
use Sentinel\Drift\Changes\FieldRemoved;
use Sentinel\Drift\Changes\FormatChanged;
use Sentinel\Drift\Changes\TypeChanged;
use Sentinel\Drift\Changes\NowNullable;
use Sentinel\Drift\Changes\RequiredNowOptional;
use Sentinel\Drift\Changes\OptionalNowRequired;
use Sentinel\Drift\Changes\EnumValueAdded;
use Sentinel\Drift\Changes\EnumValueRemoved;
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

                if (!array_key_exists($key, $newProps)) {
                    $changes[] = new FieldRemoved($propPath, $oldPropDef['type'] ?? 'unknown');
                    continue;
                }

                // --- NowNullable ---
                // Old schema had a real type; new response has null for this field.
                // We handle this here and skip the recursive diff to avoid also
                // firing TypeChanged for the same semantic change.
                $oldTypeProp = $oldPropDef['type'] ?? '';
                $newTypeProp = $newProps[$key]['type'] ?? '';

                $isNowNull = !is_array($oldTypeProp)
                    && $oldTypeProp !== 'null'
                    && ($newTypeProp === 'null' || (is_array($newTypeProp) && in_array('null', $newTypeProp, true)));

                if ($isNowNull) {
                    if (($oldPropDef['nullable'] ?? false) === true) {
                        continue;
                    }
                    $changes[] = new NowNullable($propPath);
                    // Skip recursive diff — NowNullable already describes this change.
                    // A recursive call would fire a redundant TypeChanged for the same field.
                    continue;
                }

                // --- EnumValueAdded / EnumValueRemoved ---
                if (isset($oldPropDef['enum']) && isset($newProps[$key]['enum'])) {
                    $oldEnum = $oldPropDef['enum'];
                    $newEnum = $newProps[$key]['enum'];

                    if (is_array($oldEnum) && is_array($newEnum)) {
                        foreach (array_diff($oldEnum, $newEnum) as $removed) {
                            $changes[] = new EnumValueRemoved($propPath, (string) $removed);
                        }
                        foreach (array_diff($newEnum, $oldEnum) as $added) {
                            $changes[] = new EnumValueAdded($propPath, (string) $added);
                        }
                    }
                }

                $changes = array_merge($changes, $this->diff($oldPropDef, $newProps[$key], $propPath));
            }

            foreach ($newProps as $key => $newPropDef) {
                $propPath = $path === '' ? $key : $path . '.' . $key;

                if (!array_key_exists($key, $oldProps)) {
                    $changes[] = new FieldAdded($propPath, $newPropDef['type'] ?? 'unknown');
                }
            }

            // --- RequiredNowOptional / OptionalNowRequired ---
            // Only fires when a field is ABSENT from the response entirely.
            // When a field is present but null, NowNullable handles it above.
            $oldRequired = $old['required'] ?? [];
            $newRequired = $new['required'] ?? [];

            foreach ($oldRequired as $reqField) {
                $fieldPath = $path === '' ? $reqField : $path . '.' . $reqField;

                // If it is still required, skip
                if (in_array($reqField, $newRequired, true)) {
                    continue;
                }

                // If it is completely missing from the new response properties, 
                // FieldRemoved already caught it above. Do not emit duplicate drift.
                if (!array_key_exists($reqField, $newProps)) {
                    continue;
                }

                // It is present in properties, but no longer required => RequiredNowOptional
                $changes[] = new RequiredNowOptional($fieldPath);
                // If it ALSO changed to null, NowNullable fired separately above.
            }

            foreach ($newRequired as $reqField) {
                if (!in_array($reqField, $oldRequired, true)) {
                    $fieldPath = $path === '' ? $reqField : $path . '.' . $reqField;
                    $changes[] = new OptionalNowRequired($fieldPath);
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

        return $changes;
    }
}
