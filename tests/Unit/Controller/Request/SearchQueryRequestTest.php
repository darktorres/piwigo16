<?php

declare(strict_types=1);

use Piwigo\Controller\Request\SearchQueryRequest;

test('fromArray defaults q to an empty string when absent or not a string', function (): void {
    $request = SearchQueryRequest::fromArray([]);

    expect($request->q)->toBe('');
});

test('fromArray reads q verbatim when present', function (): void {
    $request = SearchQueryRequest::fromArray(['q' => 'sunset']);

    expect($request->q)->toBe('sunset');
});

test('fromArray reports hasCatId and passes through a valid digit-only cat_id', function (): void {
    $request = SearchQueryRequest::fromArray(['cat_id' => '42']);

    expect($request->hasCatId)->toBeTrue()
        ->and($request->catId)->toBe('42');
});

test('fromArray reports hasCatId false when cat_id is absent', function (): void {
    $request = SearchQueryRequest::fromArray([]);

    expect($request->hasCatId)->toBeFalse()
        ->and($request->catId)->toBeNull();
});

test('fromArray rejects a non-digit cat_id', function (): void {
    expect(fn (): SearchQueryRequest => SearchQueryRequest::fromArray(['cat_id' => '1; DROP TABLE']))
        ->toThrow(RuntimeException::class);
});

test('fromArray reports hasTagId and passes through a valid comma-separated tag_id', function (): void {
    $request = SearchQueryRequest::fromArray(['tag_id' => '1,2,3']);

    expect($request->hasTagId)->toBeTrue()
        ->and($request->tagId)->toBe('1,2,3');
});

test('fromArray rejects a malformed tag_id', function (): void {
    expect(fn (): SearchQueryRequest => SearchQueryRequest::fromArray(['tag_id' => 'abc']))
        ->toThrow(RuntimeException::class);
});
