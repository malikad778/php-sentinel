<?php

declare(strict_types=1);

namespace Sentinel\Schema;

readonly class StoredSchema
{
    /**
     * @param string $version The hash/version of the schema
     * @param array<string, mixed> $jsonSchema The raw JSON schema document
     * @param int $sampleCount The total samples used to build it
     * @param \DateTimeImmutable $hardenedAt Timestamp of hardening
     */
    public function __construct(
        public string $version,
        public array $jsonSchema,
        public int $sampleCount,
        public \DateTimeImmutable $hardenedAt
    ) {
    }

    /**
     * Reconstitute a StoredSchema from array data.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['version'],
            is_array($data['jsonSchema']) ? $data['jsonSchema'] : [],
            (int) $data['sampleCount'],
            new \DateTimeImmutable((string) $data['hardenedAt'])
        );
    }

    /**
     * Serialize to array for storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'jsonSchema' => $this->jsonSchema,
            'sampleCount' => $this->sampleCount,
            'hardenedAt' => $this->hardenedAt->format('c'),
        ];
    }
}
