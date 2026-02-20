<?php

declare(strict_types=1);

namespace Sentinel;

use Sentinel\Drift\Severity;
use Sentinel\Schema\SchemaStoreInterface;
use Sentinel\Store\FileSchemaStore;

class Sentinel
{
    private ?SchemaStoreInterface $store = null;
    private int $sampleThreshold = 20;
    private Severity $driftSeverity = Severity::BREAKING;
    private float $additiveThreshold = 0.95;
    private bool $reharden = true;
    private int $maxStoredSamples = 50;

    public static function create(): self
    {
        return new self();
    }

    public function withStore(SchemaStoreInterface $store): self
    {
        $this->store = $store;
        return $this;
    }

    public function withSampleThreshold(int $threshold): self
    {
        $this->sampleThreshold = $threshold;
        return $this;
    }

    public function withDriftSeverity(Severity $severity): self
    {
        $this->driftSeverity = $severity;
        return $this;
    }

    public function withAdditiveThreshold(float $threshold): self
    {
        $this->additiveThreshold = $threshold;
        return $this;
    }

    public function withReharden(bool $reharden): self
    {
        $this->reharden = $reharden;
        return $this;
    }

    public function withMaxStoredSamples(int $maxSamples): self
    {
        $this->maxStoredSamples = $maxSamples;
        return $this;
    }

    public function build(): self
    {
        if ($this->store === null) {
            $this->store = new FileSchemaStore(sys_get_temp_dir() . '/sentinel');
        }

        // Logic to construct necessary composite elements will go here
        return $this;
    }

    public function getStore(): SchemaStoreInterface
    {
        if ($this->store === null) {
            throw new \RuntimeException('Store not initialized. Call build() first.');
        }

        return $this->store;
    }
    
    public function getSampleThreshold(): int
    {
        return $this->sampleThreshold;
    }
    
    public function getDriftSeverity(): Severity
    {
        return $this->driftSeverity;
    }
    
    public function getAdditiveThreshold(): float
    {
        return $this->additiveThreshold;
    }
    
    public function getReharden(): bool
    {
        return $this->reharden;
    }
    
    public function getMaxStoredSamples(): int
    {
        return $this->maxStoredSamples;
    }
}
