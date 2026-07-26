<?php

declare(strict_types=1);

use Piwigo\Controller\Request\GalleryDisplayRequest;

test('fromArray reports no flags set for an empty GET', function (): void {
    $request = GalleryDisplayRequest::fromArray([]);

    expect($request->hasImageOrder)->toBeFalse()
        ->and($request->validImageOrder)->toBeNull()
        ->and($request->hasDisplayParam)->toBeFalse()
        ->and($request->display)->toBeNull()
        ->and($request->hasCaddie)->toBeFalse()
        ->and($request->hasSlideshow)->toBeFalse();
});

test('fromArray parses a valid positive image_order', function (): void {
    $request = GalleryDisplayRequest::fromArray(['image_order' => '3']);

    expect($request->hasImageOrder)->toBeTrue()
        ->and($request->validImageOrder)->toBe(3);
});

test('fromArray reports hasImageOrder true but validImageOrder null for a non-positive value', function (): void {
    $request = GalleryDisplayRequest::fromArray(['image_order' => '-1']);

    expect($request->hasImageOrder)->toBeTrue()
        ->and($request->validImageOrder)->toBeNull();
});

test('fromArray parses the display param as a string', function (): void {
    $request = GalleryDisplayRequest::fromArray(['display' => 'square']);

    expect($request->hasDisplayParam)->toBeTrue()
        ->and($request->display)->toBe('square');
});

test('fromArray reports hasDisplayParam true but display null for a non-string value', function (): void {
    $request = GalleryDisplayRequest::fromArray(['display' => ['x']]);

    expect($request->hasDisplayParam)->toBeTrue()
        ->and($request->display)->toBeNull();
});

test('fromArray detects caddie and slideshow presence flags', function (): void {
    $request = GalleryDisplayRequest::fromArray(['caddie' => '', 'slideshow' => '']);

    expect($request->hasCaddie)->toBeTrue()
        ->and($request->hasSlideshow)->toBeTrue();
});
