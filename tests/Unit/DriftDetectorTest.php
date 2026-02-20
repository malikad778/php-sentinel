<?php

use Sentinel\Drift\DriftDetector;
use Sentinel\Drift\Changes\FieldRemoved;
use Sentinel\Drift\Changes\FieldAdded;
use Sentinel\Drift\Changes\TypeChanged;
use Sentinel\Drift\Changes\NowNullable;
use Sentinel\Drift\Changes\RequiredNowOptional;
use Sentinel\Drift\Changes\EnumValueRemoved;
use Sentinel\Drift\Severity;
use Sentinel\Schema\StoredSchema;

function makeStoredSchema(array $jsonSchema): StoredSchema {
    return new StoredSchema('sha256:abc', $jsonSchema, 10, new DateTimeImmutable());
}

it('returns null when schemas are identical', function () {
    $schema = makeStoredSchema(['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]);
    $drift  = (new DriftDetector())->detect('GET /test', $schema, $schema->jsonSchema);
    expect($drift)->toBeNull();
});

it('detects FieldRemoved as BREAKING', function () {
    $old = makeStoredSchema(['type'=>'object','properties'=>['id'=>['type'=>'integer'],'total'=>['type'=>'string']],'required'=>['id','total']]);
    $new = ['type'=>'object','properties'=>['id'=>['type'=>'integer']],'required'=>['id']];
    $drift = (new DriftDetector())->detect('GET /orders', $old, $new);
    expect($drift->severity)->toBe(Severity::BREAKING);
    expect($drift->changes[0])->toBeInstanceOf(FieldRemoved::class);
});

it('detects FieldAdded as ADDITIVE', function () {
    $old = makeStoredSchema(['type'=>'object','properties'=>['id'=>['type'=>'integer']],'required'=>['id']]);
    $new = ['type'=>'object','properties'=>['id'=>['type'=>'integer'],'name'=>['type'=>'string']],'required'=>['id','name']];
    $drift = (new DriftDetector())->detect('GET /orders', $old, $new);
    expect($drift->severity)->toBe(Severity::ADDITIVE);
    expect($drift->changes[0])->toBeInstanceOf(FieldAdded::class);
});

it('detects TypeChanged as BREAKING', function () {
    $old = makeStoredSchema(['type'=>'object','properties'=>['price'=>['type'=>'string']],'required'=>['price']]);
    $new = ['type'=>'object','properties'=>['price'=>['type'=>'integer']],'required'=>['price']];
    $drift = (new DriftDetector())->detect('GET /products', $old, $new);
    expect($drift->changes[0])->toBeInstanceOf(TypeChanged::class);
});

it('detects RequiredNowOptional as BREAKING', function () {
    $old = makeStoredSchema(['type'=>'object','properties'=>['note'=>['type'=>'string']],'required'=>['note']]);
    $new = ['type'=>'object','properties'=>['note'=>['type'=>'string']],'required'=>[]];
    $drift = (new DriftDetector())->detect('GET /orders', $old, $new);
    $types = array_map(fn($c) => get_class($c), $drift->changes);
    expect($types)->toContain(RequiredNowOptional::class);
});

it('detects NowNullable as BREAKING', function () {
    $old = makeStoredSchema(['type'=>'object','properties'=>['status'=>['type'=>'string']],'required'=>['status']]);
    $new = ['type'=>'object','properties'=>['status'=>['type'=>'null']],'required'=>['status']];
    $drift = (new DriftDetector())->detect('GET /orders', $old, $new);
    $types = array_map(fn($c) => get_class($c), $drift->changes);
    expect($types)->toContain(NowNullable::class);
});

it('detects EnumValueRemoved as BREAKING', function () {
    $old = makeStoredSchema(['type'=>'object','properties'=>['status'=>['type'=>'string','enum'=>['paid','pending','refunded']]],'required'=>['status']]);
    $new = ['type'=>'object','properties'=>['status'=>['type'=>'string','enum'=>['paid','pending']]],'required'=>['status']];
    $drift = (new DriftDetector())->detect('GET /orders', $old, $new);
    $types = array_map(fn($c) => get_class($c), $drift->changes);
    expect($types)->toContain(EnumValueRemoved::class);
});

it('returns null for nested identical schemas', function () {
    $schema = makeStoredSchema(['type'=>'object','properties'=>['order'=>['type'=>'object','properties'=>['id'=>['type'=>'integer']],'required'=>['id']]],'required'=>['order']]);
    $drift  = (new DriftDetector())->detect('GET /test', $schema, $schema->jsonSchema);
    expect($drift)->toBeNull();
});
