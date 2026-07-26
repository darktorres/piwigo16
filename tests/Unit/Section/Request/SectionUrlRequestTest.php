<?php

declare(strict_types=1);

use Piwigo\Section\Request\SectionUrlRequest;

test('fromArray returns empty string for an empty GET', function (): void {
    $request = SectionUrlRequest::fromArray([]);

    expect($request->firstGetKey)->toBe('');
});

test('fromArray returns the first GET key as a string', function (): void {
    $request = SectionUrlRequest::fromArray(['category/12-name/start-24' => '']);

    expect($request->firstGetKey)->toBe('category/12-name/start-24');
});

test('fromArray casts a purely-numeric key back to a string', function (): void {
    $request = SectionUrlRequest::fromArray(['1' => '']);

    expect($request->firstGetKey)->toBe('1');
});

test('fromArray ignores keys after the first', function (): void {
    $request = SectionUrlRequest::fromArray(['first' => '', 'second' => '']);

    expect($request->firstGetKey)->toBe('first');
});
