<?php

declare(strict_types=1);

namespace Sentinel\Store;

use Sentinel\Sampling\SampleCollection;
use Sentinel\Schema\SchemaStoreInterface;
use Sentinel\Schema\StoredSchema;

class PdoSchemaStore implements SchemaStoreInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function has(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sentinel_schemas WHERE endpoint_key = ?');
        $stmt->execute([$key]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    public function get(string $key): ?StoredSchema
    {
        $stmt = $this->pdo->prepare('SELECT schema_version, json_schema, sample_count, hardened_at FROM sentinel_schemas WHERE endpoint_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return new StoredSchema(
            $row['schema_version'],
            json_decode($row['json_schema'], true),
            (int) $row['sample_count'],
            new \DateTimeImmutable($row['hardened_at'])
        );
    }

    public function put(string $key, StoredSchema $schema): void
    {
        // Simple UPSERT or REPLACE (assuming MySQL REPLACE for simplicity, varies per engine)
        // A generic approach: delete then insert
        $this->pdo->beginTransaction();
        try {
            $stmtDel = $this->pdo->prepare('DELETE FROM sentinel_schemas WHERE endpoint_key = ?');
            $stmtDel->execute([$key]);

            $stmtIns = $this->pdo->prepare('
                INSERT INTO sentinel_schemas (endpoint_key, schema_version, json_schema, sample_count, hardened_at)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmtIns->execute([
                $key,
                $schema->version,
                json_encode($schema->jsonSchema),
                $schema->sampleCount,
                $schema->hardenedAt->format('Y-m-d H:i:s'),
            ]);

            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getSamples(string $key): SampleCollection
    {
        $stmt = $this->pdo->prepare('SELECT payload FROM sentinel_samples WHERE endpoint_key = ? ORDER BY created_at ASC');
        $stmt->execute([$key]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $samples = array_map(fn($row) => json_decode($row['payload'], true), $rows);
        
        return new SampleCollection($samples);
    }

    public function clearSamples(string $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM sentinel_samples WHERE endpoint_key = :key');
        $stmt->execute(['key' => $key]);
    }

    public function addSample(string $key, array $payload): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO sentinel_samples (endpoint_key, payload, created_at) VALUES (?, ?, ?)');
        $stmt->execute([
            $key,
            json_encode($payload),
            (new \DateTimeImmutable())->format('Y-m-d H:i:s')
        ]);
    }

    public function archive(string $key, StoredSchema $schema): void
    {
        // Move to history, clean current
        $this->pdo->beginTransaction();
        try {
            // Archive current
            $stmtArc = $this->pdo->prepare('
                INSERT INTO sentinel_schema_history (endpoint_key, schema_version, json_schema, archived_at)
                VALUES (?, ?, ?, ?)
            ');
            $stmtArc->execute([
                $key,
                $schema->version,
                json_encode($schema->jsonSchema),
                (new \DateTimeImmutable())->format('Y-m-d H:i:s')
            ]);

            // Clear active schema and samples
            $stmtDelSchema = $this->pdo->prepare('DELETE FROM sentinel_schemas WHERE endpoint_key = ?');
            $stmtDelSchema->execute([$key]);

            $stmtDelSamples = $this->pdo->prepare('DELETE FROM sentinel_samples WHERE endpoint_key = ?');
            $stmtDelSamples->execute([$key]);

            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT endpoint_key FROM sentinel_schemas');
        if ($stmt === false) {
            return [];
        }

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
