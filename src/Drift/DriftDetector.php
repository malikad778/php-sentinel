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
                
                if (!isset($newProps[$key])) {
                    $changes[] = new FieldRemoved($propPath, $oldPropDef['type'] ?? 'unknown');
                    continue;
                }

                // --- NowNullable ---
                // Old schema had non-null type, new inferred schema has ['string','null'] or type=null
                $oldTypeProp = $oldPropDef['type'] ?? '';
                $newTypeProp = $newProps[$key]['type'] ?? '';
                
                if (!is_array($oldTypeProp) && $oldTypeProp !== 'null') {
                    if ($newTypeProp === 'null' || (is_array($newTypeProp) && in_array('null', $newTypeProp))) {
                        $changes[] = new NowNullable($propPath);
                    }
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
                
                if (!isset($oldProps[$key])) {
                    $changes[] = new FieldAdded($propPath, $newPropDef['type'] ?? 'unknown');
                }
            }

            // --- RequiredNowOptional / OptionalNowRequired ---
            // Compare the 'required' arrays at the object level
            $oldRequired = $old['required'] ?? [];
            $newRequired = $new['required'] ?? [];

            foreach ($oldRequired as $reqField) {
                if (!in_array($reqField, $newRequired)) {
                    $fieldPath = $path === '' ? $reqField : $path . '.' . $reqField;
                    $changes[] = new RequiredNowOptional($fieldPath);
                }
            }

            foreach ($newRequired as $reqField) {
                if (!in_array($reqField, $oldRequired)) {
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

        // We omit deeper probabilistic checking (NowNullable, RequiredNowOptional, Enums) here for simplicity unless requested
        // as determining requiredness without the whole multi-sample sequence logic requires a complete probability matrix overlay.

        return $changes;
    }
}
