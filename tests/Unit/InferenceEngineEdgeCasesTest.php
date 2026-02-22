<?php

use Sentinel\Inference\InferenceEngine;

// ─── basic types ────────────────────────────────────────────────────────────

it('infers integer type', function () {
    $schema = (new InferenceEngine())->infer(['qty' => 3]);
    expect($schema['properties']['qty']['type'])->toBe('integer');
});

it('infers number (float) type', function () {
    $schema = (new InferenceEngine())->infer(['price' => 9.99]);
    expect($schema['properties']['price']['type'])->toBe('number');
});

it('does NOT treat a numeric string as integer', function () {
    $schema = (new InferenceEngine())->infer(['total' => '99.00']);
    expect($schema['properties']['total']['type'])->toBe('string');
});

it('infers boolean type', function () {
    $schema = (new InferenceEngine())->infer(['active' => true, 'deleted' => false]);
    expect($schema['properties']['active']['type'])->toBe('boolean');
    expect($schema['properties']['deleted']['type'])->toBe('boolean');
});

it('infers null type for null field value', function () {
    $schema = (new InferenceEngine())->infer(['note' => null]);
    expect($schema['properties']['note']['type'])->toBe('null');
});

// ─── structure ──────────────────────────────────────────────────────────────

it('handles an empty payload', function () {
    $schema = (new InferenceEngine())->infer([]);
    expect($schema['type'])->toBe('object');
    expect($schema['properties'])->toBeEmpty();
});

it('marks all top-level fields as required', function () {
    $schema = (new InferenceEngine())->infer(['id' => 1, 'name' => 'test', 'active' => true]);
    expect($schema['required'])->toEqualCanonicalizing(['id', 'name', 'active']);
});

it('handles an empty array field', function () {
    $schema = (new InferenceEngine())->infer(['tags' => []]);
    expect($schema['properties']['tags']['type'])->toBe('array');
});

it('infers item schema from a non-empty array', function () {
    $schema = (new InferenceEngine())->infer(['line_items' => [['id' => 1], ['id' => 2]]]);
    expect($schema['properties']['line_items']['type'])->toBe('array');
    expect($schema['properties']['line_items']['items']['type'])->toBe('object');
    expect($schema['properties']['line_items']['items']['properties']['id']['type'])->toBe('integer');
});

it('infers deeply nested object schemas', function () {
    $schema = (new InferenceEngine())->infer([
        'order' => [
            'billing' => [
                'address' => ['city' => 'Lahore']
            ]
        ]
    ]);
    expect($schema['properties']['order']['properties']['billing']['properties']['address']['properties']['city']['type'])
        ->toBe('string');
});

// ─── format hints ───────────────────────────────────────────────────────────

it('detects date-time format', function () {
    $schema = (new InferenceEngine())->infer(['created_at' => '2024-01-01T12:00:00Z']);
    expect($schema['properties']['created_at']['format'])->toBe('date-time');
});

it('detects date format', function () {
    $schema = (new InferenceEngine())->infer(['birthday' => '1990-05-15']);
    expect($schema['properties']['birthday']['format'])->toBe('date');
});

it('detects uuid format', function () {
    $schema = (new InferenceEngine())->infer(['ref_id' => '123e4567-e89b-12d3-a456-426614174000']);
    expect($schema['properties']['ref_id']['format'])->toBe('uuid');
});

it('does not add format for plain strings', function () {
    $schema = (new InferenceEngine())->infer(['status' => 'paid']);
    expect($schema['properties']['status'])->not->toHaveKey('format');
});

// ─── required array ─────────────────────────────────────────────────────────

it('required array contains exactly the keys present in the payload', function () {
    $schema = (new InferenceEngine())->infer(['a' => 1, 'b' => 'x', 'c' => null]);
    expect($schema['required'])->toEqualCanonicalizing(['a', 'b', 'c']);
});
