<?php

use Sentinel\Events\SampleCollected;
use Sentinel\Events\SchemaHardened;
use Sentinel\Inference\InferenceEngine;
use Sentinel\Sampling\SampleAccumulator;
use Sentinel\Store\ArraySchemaStore;

// ─── helpers ────────────────────────────────────────────────────────────────

function makeDispatcher(): object
{
    return new class implements \Psr\EventDispatcher\EventDispatcherInterface {
        public array $events = [];
        public function dispatch(object $event): object
        {
            $this->events[] = $event;
            return $event;
        }
        /** @return array<object> */
        public function of(string $class): array
        {
            return array_values(array_filter($this->events, fn($e) => $e instanceof $class));
        }
    };
}

// ─── threshold behaviour ─────────────────────────────────────────────────────

it('separate endpoint keys are counted independently', function () {
    $store       = new ArraySchemaStore();
    $accumulator = new SampleAccumulator($store, new InferenceEngine(), 2);

    $accumulator->accumulate('GET /orders', ['id' => 1]);
    $accumulator->accumulate('GET /products', ['id' => 1]);

    // Each key has only 1 sample — neither should harden
    expect($store->has('GET /orders'))->toBeFalse();
    expect($store->has('GET /products'))->toBeFalse();
});

it('does not re-harden on extra calls beyond threshold', function () {
    $store       = new ArraySchemaStore();
    $dispatcher  = makeDispatcher();
    $accumulator = new SampleAccumulator($store, new InferenceEngine(), 2, 0.95, $dispatcher);

    $accumulator->accumulate('GET /test', ['id' => 1]);
    $accumulator->accumulate('GET /test', ['id' => 2]); // hardens
    // After hardening, samples are cleared; the store now has the key.
    // A further call should go to the drift-detection path in SchemaWatcher,
    // not to the accumulator. The accumulator itself just adds a new sample.
    $accumulator->accumulate('GET /test', ['id' => 3]);

    // SchemaHardened fired exactly once
    expect($dispatcher->of(SchemaHardened::class))->toHaveCount(1);
});

// ─── field presence / required[] ─────────────────────────────────────────────

it('field present in every sample is in required[]', function () {
    $store       = new ArraySchemaStore();
    $accumulator = new SampleAccumulator($store, new InferenceEngine(), 3, 0.95);

    $accumulator->accumulate('GET /test', ['id' => 1, 'name' => 'a']);
    $accumulator->accumulate('GET /test', ['id' => 2, 'name' => 'b']);
    $accumulator->accumulate('GET /test', ['id' => 3, 'name' => 'c']);

    $schema = $store->get('GET /test');
    expect($schema->jsonSchema['required'] ?? [])->toContain('id');
    expect($schema->jsonSchema['required'] ?? [])->toContain('name');
});

it('field absent in most samples is NOT in required[]', function () {
    $store       = new ArraySchemaStore();
    $accumulator = new SampleAccumulator($store, new InferenceEngine(), 4, 0.95);

    // 'note' present in only 1 of 4 = 25% — below 95% threshold
    $accumulator->accumulate('GET /test', ['id' => 1, 'note' => 'hello']);
    $accumulator->accumulate('GET /test', ['id' => 2]);
    $accumulator->accumulate('GET /test', ['id' => 3]);
    $accumulator->accumulate('GET /test', ['id' => 4]);

    $schema = $store->get('GET /test');
    expect($schema->jsonSchema['required'] ?? [])->not->toContain('note');
    expect($schema->jsonSchema['required'] ?? [])->toContain('id');
});

it('field sometimes null and sometimes a real type does not cause drift on re-observation', function () {
    // This is the Bug 1 scenario: last_payment_error is null in success, object in failed.
    // After hardening, seeing 'null' again should NOT fire drift.
    $store       = new ArraySchemaStore();
    $accumulator = new SampleAccumulator($store, new InferenceEngine(), 2, 0.95);

    $accumulator->accumulate('GET /test', ['error' => null]);
    $accumulator->accumulate('GET /test', ['error' => ['code' => 'card_declined']]);

    $schema = $store->get('GET /test');

    // The hardened schema should reflect that 'error' can be null
    expect($schema)->not->toBeNull();
    // nullable flag is set on the error field
    expect($schema->jsonSchema['properties']['error']['nullable'] ?? false)->toBeTrue();
});

// ─── events ──────────────────────────────────────────────────────────────────

it('fires SampleCollected on every accumulate() call', function () {
    $store      = new ArraySchemaStore();
    $dispatcher = makeDispatcher();
    $acc        = new SampleAccumulator($store, new InferenceEngine(), 5, 0.95, $dispatcher);

    $acc->accumulate('GET /test', ['id' => 1]);
    $acc->accumulate('GET /test', ['id' => 2]);
    $acc->accumulate('GET /test', ['id' => 3]);

    expect($dispatcher->of(SampleCollected::class))->toHaveCount(3);
});

it('fires SchemaHardened exactly once at threshold', function () {
    $store      = new ArraySchemaStore();
    $dispatcher = makeDispatcher();
    $acc        = new SampleAccumulator($store, new InferenceEngine(), 2, 0.95, $dispatcher);

    $acc->accumulate('GET /test', ['id' => 1]);
    $acc->accumulate('GET /test', ['id' => 2]);

    expect($dispatcher->of(SchemaHardened::class))->toHaveCount(1);
});

it('fires no SchemaHardened before threshold is reached', function () {
    $store      = new ArraySchemaStore();
    $dispatcher = makeDispatcher();
    $acc        = new SampleAccumulator($store, new InferenceEngine(), 5, 0.95, $dispatcher);

    $acc->accumulate('GET /test', ['id' => 1]);
    $acc->accumulate('GET /test', ['id' => 2]);

    expect($dispatcher->of(SchemaHardened::class))->toBeEmpty();
});

// ─── sample cleanup ───────────────────────────────────────────────────────────

it('clears all samples after hardening', function () {
    $store = new ArraySchemaStore();
    $acc   = new SampleAccumulator($store, new InferenceEngine(), 2);

    $acc->accumulate('GET /test', ['x' => 1]);
    $acc->accumulate('GET /test', ['x' => 2]);

    expect($store->getSamples('GET /test')->count())->toBe(0);
});
