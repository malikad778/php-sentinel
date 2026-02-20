<?php

declare(strict_types=1);

namespace Sentinel\Inference;

class FormatHintDetector
{
    /**
     * Attempts to detect standard formats in a string value.
     * Returns the detected format as a string (e.g., 'date-time', 'uuid') or null.
     */
    public static function detect(string $value): ?string
    {
        if (self::isUuid($value)) {
            return 'uuid';
        }

        if (self::isDateTime($value)) {
            return 'date-time';
        }

        if (self::isDate($value)) {
            return 'date';
        }

        return null;
    }

    private static function isUuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    private static function isDateTime(string $value): bool
    {
        // Matches ISO 8601 date-time, e.g. 2024-01-01T12:00:00Z or '2024-01-01T12:00:00+00:00'
        return 1 === preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value);
    }

    private static function isDate(string $value): bool
    {
        // Simple YYYY-MM-DD
        return 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }
}
