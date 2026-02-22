<?php

use Sentinel\Inference\InferenceEngine;

it('infers object definitions with required properties', function () {
    $engine = new InferenceEngine();
    
    $payload = [
        'id' => 1234,
        'name' => 'John Doe',
        'is_active' => true,
    ];

    $schema = $engine->infer($payload);

    expect($schema['type'])->toBe('object')
        ->and($schema['properties']['id']['type'])->toBe('integer')
        ->and($schema['properties']['name']['type'])->toBe('string')
        ->and($schema['properties']['is_active']['type'])->toBe('boolean')
        ->and($schema['required'])->toEqualCanonicalizing(['id', 'name', 'is_active']);
});

it('detects nested arrays', function () {
    $engine = new InferenceEngine();
    
    $payload = [
        'users' => [
            ['id' => 1],
            ['id' => 2]
        ]
    ];

    $schema = $engine->infer($payload);
    
    expect($schema['properties']['users']['type'])->toBe('array')
        ->and($schema['properties']['users']['items']['type'])->toBe('object')
        ->and($schema['properties']['users']['items']['properties']['id']['type'])->toBe('integer');
});

it('detects format hints', function () {
    $engine = new InferenceEngine();

    $payload = [
        'created_at' => '2024-01-01T12:00:00Z',
        'ref_id' => '123e4567-e89b-12d3-a456-426614174000',
        'birthday' => '1990-01-01',
    ];

    $schema = $engine->infer($payload);

    expect($schema['properties']['created_at']['format'])->toBe('date-time')
        ->and($schema['properties']['ref_id']['format'])->toBe('uuid')
        ->and($schema['properties']['birthday']['format'])->toBe('date');
});
