<?php

use Sentinel\Inference\InferenceEngine;
use Sentinel\Sampling\SampleAccumulator;
use Sentinel\Store\ArraySchemaStore;

it('does not harden before threshold is reached', function () {
    $store       = new ArraySchemaStore();
    $accumulator = new SampleAccumulator($store, new InferenceEngine(), 3);

    $accumulator->accumulate('GET /test', ['id' => 1]);
    $accumulator->accumulate('GET /test', ['id' => 2]);

    expect($store->has('GET /test'))->toBeFalse();
});

it('hardens schema exactly at threshold', function () {
    $store       = new ArraySchemaStore();
    $accumulator = new SampleAccumulator($store, new InferenceEngine(), 3);

    $accumulator->accumulate('GET /test', ['id' => 1]);
    $accumulator->accumulate('GET /test', ['id' => 2]);
    $hardened = $accumulator->accumulate('GET /test', ['id' => 3]);

    expect($hardened)->toBeTrue();
    expect($store->has('GET /test'))->toBeTrue();
    expect($store->get('GET /test')->sampleCount)->toBe(3);
});

it('samples are cleared after hardening', function () {
    $store       = new ArraySchemaStore();
    $accumulator = new SampleAccumulator($store, new InferenceEngine(), 2);

    $accumulator->accumulate('GET /test', ['x' => 1]);
    $accumulator->accumulate('GET /test', ['x' => 2]);

    expect($store->getSamples('GET /test')->count())->toBe(0);
});

it('marks field as optional when absent in some samples', function () {
    $store       = new ArraySchemaStore();
    $accumulator = new SampleAccumulator($store, new InferenceEngine(), 3, 0.95);

    // 'note' field only present in 1 of 3 samples = 33% < 95% threshold
    $accumulator->accumulate('GET /test', ['id' => 1, 'note' => 'hello']);
    $accumulator->accumulate('GET /test', ['id' => 2]);
    $accumulator->accumulate('GET /test', ['id' => 3]);

    $schema = $store->get('GET /test');
    expect($schema->jsonSchema['required'])->not->toContain('note');
    expect($schema->jsonSchema['required'])->toContain('id');
});
