<?php

declare(strict_types=1);

namespace Sentinel\Store;

use Sentinel\Sampling\SampleCollection;
use Sentinel\Schema\SchemaStoreInterface;
use Sentinel\Schema\StoredSchema;

class FileSchemaStore implements SchemaStoreInterface
{
    public function __construct(private readonly string $storageDirectory)
    {
        if (!is_dir($this->storageDirectory)) {
            mkdir($this->storageDirectory, 0755, true);
        }
    }

    public function has(string $key): bool
    {
        return file_exists($this->getSchemaPath($key));
    }

    public function get(string $key): ?StoredSchema
    {
        $path = $this->getSchemaPath($key);
        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        return StoredSchema::fromArray($data);
    }

    public function put(string $key, StoredSchema $schema): void
    {
        $path = $this->getSchemaPath($key);
        file_put_contents($path, json_encode($schema->toArray(), JSON_PRETTY_PRINT));
    }

    public function getSamples(string $key): SampleCollection
    {
        $path = $this->getSamplesPath($key);
        if (!file_exists($path)) {
            return new SampleCollection();
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return new SampleCollection();
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return new SampleCollection();
        }

        return new SampleCollection($data);
    }

    public function addSample(string $key, array $payload): void
    {
        $collection = $this->getSamples($key);
        $collection->add($payload);

        $path = $this->getSamplesPath($key);
        file_put_contents($path, json_encode($collection->all(), JSON_PRETTY_PRINT));
    }

    public function archive(string $key, StoredSchema $schema): void
    {
        $archiveDir = $this->storageDirectory . DIRECTORY_SEPARATOR . 'archive';
        if (!is_dir($archiveDir)) {
            mkdir($archiveDir, 0755, true);
        }

        $archivePath = $archiveDir . DIRECTORY_SEPARATOR . $this->sanitizeKey($key) . '_' . $schema->version . '.json';
        file_put_contents($archivePath, json_encode($schema->toArray(), JSON_PRETTY_PRINT));

        // Optionally, reset the hardened schema and samples for the key here?
        // The process dictates resampling will begin, which probably needs sample clearing.
        $this->clearSamples($key);
        
        // Remove the current hardened schema so a new baseline is formed
        if (file_exists($this->getSchemaPath($key))) {
            unlink($this->getSchemaPath($key));
        }
    }

    public function all(): array
    {
        $keys = [];
        $files = glob($this->storageDirectory . DIRECTORY_SEPARATOR . '*.json');
        
        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $filename = basename($file, '.json');
            // Sample files end with .samples.json, schema files end with .json
            if (!str_ends_with($filename, '.samples')) {
                $keys[] = $this->unsanitizeKey($filename);
            }
        }
        
        return $keys;
    }

    private function clearSamples(string $key): void
    {
        $path = $this->getSamplesPath($key);
        if (file_exists($path)) {
            unlink($path);
        }
    }

    private function sanitizeKey(string $key): string
    {
        // simplistic hashing or encoding for keys as filenames
        // Base64url encoding to make it filesystem safe seamlessly
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($key));
    }

    private function unsanitizeKey(string $safeKey): string
    {
        $base64 = str_replace(['-', '_'], ['+', '/'], $safeKey);
        $decoded = base64_decode($base64);
        return is_string($decoded) ? $decoded : $safeKey;
    }

    private function getSchemaPath(string $key): string
    {
        return $this->storageDirectory . DIRECTORY_SEPARATOR . $this->sanitizeKey($key) . '.json';
    }

    private function getSamplesPath(string $key): string
    {
        return $this->storageDirectory . DIRECTORY_SEPARATOR . $this->sanitizeKey($key) . '.samples.json';
    }
}
