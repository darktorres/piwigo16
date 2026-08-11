<?php

declare(strict_types=1);

use Piwigo\Ws\Request\HistorySearchPageRequest;

test('fromArray parses a numeric start offset', function (): void {
    $request = HistorySearchPageRequest::fromArray([
        'start' => '40',
    ]);

    expect($request->start)
        ->toBe(40);
});

test('fromArray defaults to 0 when start is missing', function (): void {
    $request = HistorySearchPageRequest::fromArray([]);

    expect($request->start)
        ->toBe(0);
});

test('fromArray defaults to 0 when start is not numeric', function (): void {
    $request = HistorySearchPageRequest::fromArray([
        'start' => 'not-a-number',
    ]);

    expect($request->start)
        ->toBe(0);
});
