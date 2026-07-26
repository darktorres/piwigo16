<?php

declare(strict_types=1);

use Piwigo\Category\Request\CategorySlideshowRequest;

test('fromArray returns empty string for an empty GET', function (): void {
    $request = CategorySlideshowRequest::fromArray([]);

    expect($request->slideshow)->toBe('');
});

test('fromArray reads a string slideshow value', function (): void {
    $request = CategorySlideshowRequest::fromArray(['slideshow' => '1']);

    expect($request->slideshow)->toBe('1');
});

test('fromArray narrows a non-string value to an empty string', function (): void {
    $request = CategorySlideshowRequest::fromArray(['slideshow' => ['nested']]);

    expect($request->slideshow)->toBe('');
});
