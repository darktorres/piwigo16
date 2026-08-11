<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\PhotoDispatchRequest;
use Piwigo\Validation\InputValidator;

test('fromArray parses a numeric image_id and a known tab', function (): void {
    $request = PhotoDispatchRequest::fromArray([
        'image_id' => '42',
        'tab' => 'coi',
    ], ['properties', 'coi', 'formats'], new InputValidator());

    expect($request->imageId)
        ->toBe('42')
        ->and($request->tab)
        ->toBe('coi');
});

test('fromArray defaults to an empty image_id and properties tab when absent', function (): void {
    $request = PhotoDispatchRequest::fromArray([], ['properties', 'coi', 'formats'], new InputValidator());

    expect($request->imageId)
        ->toBe('')
        ->and($request->tab)
        ->toBe('properties');
});

test('fromArray falls back to properties for an unknown tab', function (): void {
    $request = PhotoDispatchRequest::fromArray([
        'tab' => 'not-a-real-tab',
    ], ['properties', 'coi', 'formats'], new InputValidator());

    expect($request->tab)
        ->toBe('properties');
});

test('fromArray rejects a non-digit image_id', function (): void {
    expect(fn (): PhotoDispatchRequest => PhotoDispatchRequest::fromArray([
        'image_id' => '1; DROP TABLE',
    ], ['properties', 'coi', 'formats'], new InputValidator()))
        ->toThrow(RuntimeException::class);
});
