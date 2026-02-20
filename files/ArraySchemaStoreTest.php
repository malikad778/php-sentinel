<?php

use Sentinel\Schema\StoredSchema;
use Sentinel\Store\ArraySchemaStore;

// ─── helpers ────────────────────────────────────────────────────────────────

function makeSchema(string $version = 'sha256:abc'): StoredSchema
{
    return new StoredSchema($version, ['type' => 'object', 'properties' => []], 5, new DateTimeImmutable());
}

// ─── has() ──────────────────────────────────────────────────────────────────

it('returns false for has() on an empty store', function () {
    $store = new ArraySchemaStore();
    expect($store->has('GET /test'))->toBeFalse();
});

it('returns true for has() after put()', function () {
    $store = new ArraySchemaStore();
    $store->put('GET /test', makeSchema());
    expect($store->has('GET /test'))->toBeTrue();
});

// ─── get() ──────────────────────────────────────────────────────────────────

it('returns null for get() on unknown key', function () {
    $store = new ArraySchemaStore();
    expect($store->get('GET /unknown'))->toBeNull();
});

it('returns the stored schema for get() after put()', function () {
    $store  = new ArraySchemaStore();
    $schema = makeSchema('sha256:xyz');
    $store->put('GET /orders', $schema);

    expect($store->get('GET /orders')->version)->toBe('sha256:xyz');
});

// ─── samples ────────────────────────────────────────────────────────────────

it('accumulates samples with addSample()', function () {
    $store = new ArraySchemaStore();
    $store->addSample('GET /test', ['id' => 1]);
    $store->addSample('GET /test', ['id' => 2]);

    expect($store->getSamples('GET /test')->count())->toBe(2);
});

it('returns empty SampleCollection for unknown key in getSamples()', function () {
    $store = new ArraySchemaStore();
    expect($store->getSamples('GET /nothing')->count())->toBe(0);
});

it('clearSamples() removes all samples for the key', function () {
    $store = new ArraySchemaStore();
    $store->addSample('GET /test', ['id' => 1]);
    $store->clearSamples('GET /test');

    expect($store->getSamples('GET /test')->count())->toBe(0);
});

it('clearSamples() does not affect other keys', function () {
    $store = new ArraySchemaStore();
    $store->addSample('GET /orders', ['id' => 1]);
    $store->addSample('GET /products', ['id' => 2]);
    $store->clearSamples('GET /orders');

    expect($store->getSamples('GET /products')->count())->toBe(1);
});

// ─── archive() ──────────────────────────────────────────────────────────────

it('archive() removes schema from active store', function () {
    $store  = new ArraySchemaStore();
    $schema = makeSchema();
    $store->put('GET /test', $schema);
    $store->archive('GET /test', $schema);

    expect($store->has('GET /test'))->toBeFalse();
});

it('archive() stores the schema in archives', function () {
    $store  = new ArraySchemaStore();
    $schema = makeSchema('sha256:old');
    $store->put('GET /test', $schema);
    $store->archive('GET /test', $schema);

    expect($store->getArchives('GET /test'))->toHaveCount(1);
    expect($store->getArchives('GET /test')[0]->version)->toBe('sha256:old');
});

it('archive() also clears samples', function () {
    $store  = new ArraySchemaStore();
    $schema = makeSchema();
    $store->addSample('GET /test', ['id' => 1]);
    $store->put('GET /test', $schema);
    $store->archive('GET /test', $schema);

    expect($store->getSamples('GET /test')->count())->toBe(0);
});

// ─── all() ──────────────────────────────────────────────────────────────────

it('all() returns empty array on fresh store', function () {
    $store = new ArraySchemaStore();
    expect($store->all())->toBeEmpty();
});

it('all() returns keys of active schemas only', function () {
    $store = new ArraySchemaStore();
    $store->put('GET /orders', makeSchema());
    $store->put('POST /payments', makeSchema());

    expect($store->all())->toHaveCount(2);
    expect($store->all())->toContain('GET /orders');
    expect($store->all())->toContain('POST /payments');
});

it('all() excludes archived keys', function () {
    $store  = new ArraySchemaStore();
    $schema = makeSchema();
    $store->put('GET /orders', $schema);
    $store->put('GET /products', $schema);
    $store->archive('GET /orders', $schema);

    expect($store->all())->toHaveCount(1);
    expect($store->all())->toContain('GET /products');
    expect($store->all())->not->toContain('GET /orders');
});

// ─── reset() ────────────────────────────────────────────────────────────────

it('reset() clears all schemas, samples, and archives', function () {
    $store  = new ArraySchemaStore();
    $schema = makeSchema();
    $store->put('GET /test', $schema);
    $store->addSample('GET /test', ['id' => 1]);
    $store->archive('GET /test', $schema);
    $store->reset();

    expect($store->all())->toBeEmpty();
    expect($store->getSamples('GET /test')->count())->toBe(0);
    expect($store->getArchives('GET /test'))->toBeEmpty();
});
