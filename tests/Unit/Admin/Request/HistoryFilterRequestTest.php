<?php

declare(strict_types=1);

use Piwigo\Admin\Request\HistoryFilterRequest;

test('fromArray defaults to no filters when nothing is present', function (): void {
    $request = HistoryFilterRequest::fromArray([]);

    expect($request->ip)->toBeNull()
        ->and($request->imageId)->toBeNull()
        ->and($request->userId)->toBe(-1)
        ->and($request->hasAnyFilter)->toBeFalse();
});

test('fromArray passes ip/image_id straight through', function (): void {
    $request = HistoryFilterRequest::fromArray(['filter_ip' => '192.168.1.1', 'filter_image_id' => '42']);

    expect($request->ip)->toBe('192.168.1.1')
        ->and($request->imageId)->toBe('42')
        ->and($request->hasAnyFilter)->toBeTrue();
});

test('fromArray parses a numeric filter_user_id', function (): void {
    $request = HistoryFilterRequest::fromArray(['filter_user_id' => '7']);

    expect($request->userId)->toBe(7)
        ->and($request->hasAnyFilter)->toBeTrue();
});

test('fromArray rejects a malformed filter_ip', function (): void {
    expect(fn (): HistoryFilterRequest => HistoryFilterRequest::fromArray(['filter_ip' => 'not-an-ip']))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a malformed filter_image_id', function (): void {
    expect(fn (): HistoryFilterRequest => HistoryFilterRequest::fromArray(['filter_image_id' => 'abc']))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a malformed filter_user_id', function (): void {
    expect(fn (): HistoryFilterRequest => HistoryFilterRequest::fromArray(['filter_user_id' => 'abc']))
        ->toThrow(RuntimeException::class);
});

test('fromArray defaults userId to -1 when filter_user_id is present but empty', function (): void {
    // '' is "empty" per InputValidator's own emptyValue() check (so
    // validate() no-ops without checking the digit pattern) but is NOT
    // is_numeric(), distinguishing the isset()+is_numeric() AND from an OR.
    $request = HistoryFilterRequest::fromArray(['filter_user_id' => '']);

    expect($request->userId)->toBe(-1);
});

test('fromArray reports hasAnyFilter true when only filter_ip is present', function (): void {
    // Distinct from the ip+image_id-together case above -- exactly one of
    // the first two isset() checks true (not both) is what actually
    // distinguishes `||` from a `&&` mixed into the same chain.
    $request = HistoryFilterRequest::fromArray(['filter_ip' => '192.168.1.1']);

    expect($request->hasAnyFilter)->toBeTrue();
});
