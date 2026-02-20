<?php

declare(strict_types=1);

namespace Sentinel\Middleware;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Sentinel\Drift\DriftDetector;
use Sentinel\Drift\DriftReporter;
use Sentinel\Events\SchemaDriftDetected; // Actually dispatched via Reporter, but listed
use Sentinel\Inference\InferenceEngine;
use Sentinel\Normalization\EndpointNormalizer;
use Sentinel\Sampling\SampleAccumulator;
use Sentinel\Sentinel;

class SchemaWatcher implements ClientInterface
{
    private SampleAccumulator $accumulator;
    private DriftDetector $driftDetector;
    private InferenceEngine $inferenceEngine;
    private EndpointNormalizer $normalizer;

    public function __construct(
        private readonly ClientInterface $innerClient,
        private readonly Sentinel $sentinel,
        private readonly DriftReporter $reporter
    ) {
        $this->inferenceEngine = new InferenceEngine();
        $this->driftDetector = new DriftDetector();
        $this->normalizer = new EndpointNormalizer();
        $this->accumulator = new SampleAccumulator(
            $sentinel->getStore(),
            $this->inferenceEngine,
            $sentinel->getSampleThreshold()
        );
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // 1. Send request normally.
        $response = $this->innerClient->sendRequest($request);

        // 2. Profile the response silently
        try {
            $this->profile($request, $response);
        } catch (\Throwable $e) {
            // Fail silently so we never break the caller's execution natively.
            // A robust logger could be tied in here as specified but omitted for brevity.
        }

        // 3. Return untouched response
        return $response;
    }

    private function profile(RequestInterface $request, ResponseInterface $response): void
    {
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return; // We only profile successful responses
        }

        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'application/json')) {
            return;
        }

        $body = (string) $response->getBody();
        $response->getBody()->rewind(); // rewind so caller can still read it

        if ($body === '') {
            return;
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return;
        }

        $endpointKey = $this->normalizer->normalize($request->getMethod(), (string) $request->getUri());

        $store = $this->sentinel->getStore();

        if (!$store->has($endpointKey)) {
            // Hardening in progress or sampling
            $this->accumulator->accumulate($endpointKey, $payload);
            return;
        }

        // It has a hardened schema.
        $hardenedSchema = $store->get($endpointKey);
        if ($hardenedSchema === null) {
            return;
        }

        $inferred = $this->inferenceEngine->infer($payload);
        $drift = $this->driftDetector->detect($endpointKey, $hardenedSchema, $inferred);

        if ($drift !== null) {
            $this->reporter->report($drift);

            // If breaking or additive and auto-reharden is true, archive and restart sampling
            if ($this->sentinel->getReharden()) {
                $store->archive($endpointKey, $hardenedSchema);
                $this->accumulator->accumulate($endpointKey, $payload);
            }
        }
    }
}
