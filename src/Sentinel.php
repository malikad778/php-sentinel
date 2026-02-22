<?php

declare(strict_types=1);

namespace Sentinel;

use Psr\EventDispatcher\EventDispatcherInterface;
use Sentinel\Drift\DriftDetector;
use Sentinel\Drift\DriftReporter;
use Sentinel\Drift\Severity;
use Sentinel\Inference\InferenceEngine;
use Sentinel\Normalization\EndpointNormalizer;
use Sentinel\Sampling\SampleAccumulator;
use Sentinel\Schema\SchemaStoreInterface;
use Sentinel\Store\FileSchemaStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Sentinel
{
    private ?SchemaStoreInterface $store = null;
    private int $sampleThreshold = 20;
    private Severity $driftSeverity = Severity::BREAKING;
    private float $additiveThreshold = 0.95;
    private bool $reharden = true;
    private ?EventDispatcherInterface $dispatcher = null;
    private ?LoggerInterface $logger = null;
    private int $maxStoredSamples = 50;

    private ?EndpointNormalizer $normalizer = null;
    private ?InferenceEngine $engine = null;
    private ?SampleAccumulator $accumulator = null;
    private ?DriftDetector $detector = null;
    private ?DriftReporter $reporter = null;

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

    public function withDispatcher(EventDispatcherInterface $dispatcher): self
    {
        $this->dispatcher = $dispatcher;
        return $this;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
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

        $this->normalizer  = new EndpointNormalizer();
        $this->engine      = new InferenceEngine();
        $this->accumulator = new SampleAccumulator($this->getStore(), $this->engine, $this->sampleThreshold, $this->additiveThreshold, $this->getDispatcher());
        $this->detector    = new DriftDetector();
        $this->reporter    = new DriftReporter($this->getDispatcher(), $this->getLogger());

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

    public function getDispatcher(): EventDispatcherInterface
    {
        return $this->dispatcher ?? new class implements EventDispatcherInterface {
            public function dispatch(object $event): object { return $event; }
        };
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }

    /**
     * @param array<mixed> $payload
     */
    public function process(string $method, string $uri, int $statusCode, array $payload): void
    {
        if ($statusCode < 200 || $statusCode >= 300) {
            return;
        }

        if ($this->normalizer === null) {
            throw new \RuntimeException('Sentinel not built. Call build() first.');
        }

        $endpointKey = $this->normalizer->normalize($method, $uri);
        $store       = $this->getStore();

        if (!$store->has($endpointKey)) {
            $this->accumulator->accumulate($endpointKey, $payload);
            return;
        }

        $hardened = $store->get($endpointKey);
        if ($hardened === null) {
            return;
        }

        $inferred = $this->engine->infer($payload);
        $drift    = $this->detector->detect($endpointKey, $hardened, $inferred);

        if ($drift !== null) {
            $this->reporter->report($drift);

            if ($this->reharden) {
                $store->archive($endpointKey, $hardened);
                $this->accumulator->accumulate($endpointKey, $payload);
            }
        }
    }
    
    public function getMaxStoredSamples(): int
    {
        return $this->maxStoredSamples;
    }
}
