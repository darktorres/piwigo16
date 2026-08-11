<?php

declare(strict_types=1);

use Piwigo\Controller\Request\GalleryDisplayRequest;

test('fromArray reports no flags set for an empty GET', function (): void {
    $request = GalleryDisplayRequest::fromArray([]);

    expect($request->hasImageOrder)
        ->toBeFalse()
        ->and($request->validImageOrder)
        ->toBeNull()
        ->and($request->hasDisplayParam)
        ->toBeFalse()
        ->and($request->display)
        ->toBeNull()
        ->and($request->hasCaddie)
        ->toBeFalse()
        ->and($request->hasSlideshow)
        ->toBeFalse();
});

test('fromArray parses a valid positive image_order', function (): void {
    $request = GalleryDisplayRequest::fromArray([
        'image_order' => '3',
    ]);

    expect($request->hasImageOrder)
        ->toBeTrue()
        ->and($request->validImageOrder)
        ->toBe(3);
});

test('fromArray reports hasImageOrder true but validImageOrder null for a non-positive value', function (): void {
    $request = GalleryDisplayRequest::fromArray([
        'image_order' => '-1',
    ]);

    expect($request->hasImageOrder)
        ->toBeTrue()
        ->and($request->validImageOrder)
        ->toBeNull();
});

test('fromArray rejects a fractional image_order whose int-cast is not itself positive', function (): void {
    // Kills the RemoveIntegerCast mutant on the condition's own (int)
    // cast: '0.5' is_numeric() but compares as 0.5 > 0 = true when
    // compared raw (uncast) vs. (int) '0.5' = 0, 0 > 0 = false when
    // properly cast first -- real and mutant disagree on whether
    // validImageOrder ends up null or 0.
    $request = GalleryDisplayRequest::fromArray([
        'image_order' => '0.5',
    ]);

    expect($request->validImageOrder)
        ->toBeNull();
});

test('fromArray rejects image_order exactly 0', function (): void {
    // Kills both GreaterToGreaterOrEqual (> 0 -> >= 0) and
    // DecrementInteger (> 0 -> > -1): only an exact-0 input makes both
    // of those mutated conditions true while the real one is false.
    $request = GalleryDisplayRequest::fromArray([
        'image_order' => '0',
    ]);

    expect($request->validImageOrder)
        ->toBeNull();
});

test('fromArray accepts image_order exactly 1', function (): void {
    // Kills IncrementInteger (> 0 -> > 1): only an exact-1 input makes
    // the real condition true while the mutated one is false.
    $request = GalleryDisplayRequest::fromArray([
        'image_order' => '1',
    ]);

    expect($request->validImageOrder)
        ->toBe(1);
});

test('fromArray parses the display param as a string', function (): void {
    $request = GalleryDisplayRequest::fromArray([
        'display' => 'square',
    ]);

    expect($request->hasDisplayParam)
        ->toBeTrue()
        ->and($request->display)
        ->toBe('square');
});

test('fromArray reports hasDisplayParam true but display null for a non-string value', function (): void {
    $request = GalleryDisplayRequest::fromArray([
        'display' => ['x'],
    ]);

    expect($request->hasDisplayParam)
        ->toBeTrue()
        ->and($request->display)
        ->toBeNull();
});

test('fromArray detects caddie and slideshow presence flags', function (): void {
    $request = GalleryDisplayRequest::fromArray([
        'caddie' => '',
        'slideshow' => '',
    ]);

    expect($request->hasCaddie)
        ->toBeTrue()
        ->and($request->hasSlideshow)
        ->toBeTrue();
});
