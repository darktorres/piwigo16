<?php

declare(strict_types=1);

use Piwigo\Filter\Request\RecentFilterRequest;

test('fromArray reports absent for an empty GET', function (): void {
    $request = RecentFilterRequest::fromArray([]);

    expect($request->filterPresent)->toBeFalse()
        ->and($request->filterValue)->toBeNull();
});

test('fromArray marks filterPresent even for a non-string value', function (): void {
    $request = RecentFilterRequest::fromArray(['filter' => ['nested']]);

    expect($request->filterPresent)->toBeTrue()
        ->and($request->filterValue)->toBeNull();
});

test('fromArray reads a string filter value', function (): void {
    $request = RecentFilterRequest::fromArray(['filter' => 'start-recent-7']);

    expect($request->filterPresent)->toBeTrue()
        ->and($request->filterValue)->toBe('start-recent-7');
});
