<?php

declare(strict_types=1);

use Piwigo\Admin\Maintenance\Request\DerivativesTypeRequest;
use Piwigo\Validation\InputValidator;

test('fromArray defaults to an empty string when the type param is absent', function (): void {
    $request = DerivativesTypeRequest::fromArray([], new InputValidator());

    expect($request->typesStr)
        ->toBe('');
});

test('fromArray accepts the "all" sentinel', function (): void {
    $request = DerivativesTypeRequest::fromArray([
        'type' => 'all',
    ], new InputValidator());

    expect($request->typesStr)
        ->toBe('all');
});

test('fromArray accepts an underscore-joined list of derivative types', function (): void {
    $request = DerivativesTypeRequest::fromArray([
        'type' => 'thumb_2small_xlarge',
    ], new InputValidator());

    expect($request->typesStr)
        ->toBe('thumb_2small_xlarge');
});

test('fromArray rejects a type containing regex metacharacters', function (): void {
    expect(fn (): DerivativesTypeRequest => DerivativesTypeRequest::fromArray([
        'type' => 'thumb.*',
    ], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a type containing a path separator', function (): void {
    expect(fn (): DerivativesTypeRequest => DerivativesTypeRequest::fromArray([
        'type' => '../../etc',
    ], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArray coerces a non-string type param to an empty string', function (): void {
    $request = DerivativesTypeRequest::fromArray([
        'type' => [
            'nested' => 'array',
        ],
    ], new InputValidator());

    expect($request->typesStr)
        ->toBe('');
});
